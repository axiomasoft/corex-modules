<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Contracts\FeatureFlagDefinition;
use CoreX\Modules\Enums\Capability;
use CoreX\Modules\Exceptions\CompilationException;
use CoreX\Modules\Extend\Api;
use CoreX\Modules\Extend\Entity;
use CoreX\Modules\Extend\EntityField;
use CoreX\Modules\Extend\Extender;
use CoreX\Modules\Extend\Filament;
use CoreX\Modules\Extend\Menu;
use CoreX\Modules\Extend\Permissions;
use CoreX\Modules\Extend\Routes;
use CoreX\Modules\Extend\Workflow;
use CoreX\Modules\Manifest;
use CoreX\Modules\Registry\EntityDefinition;
use CoreX\Modules\Registry\FieldDefinition;
use CoreX\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Applies the closed 8-extender set to a set of manifests in a fixed,
 * deterministic pipeline: Entity → EntityField → Permissions → Api →
 * Filament → Menu → Routes → Workflow. Output is an in-memory
 * CompiledRegistry — atomic file write and hot-path reads belong to
 * ModuleRegistry, not here.
 *
 * @internal spec: B-10 §4.2/§5.2, P1.5
 */
final class RegistryCompiler
{
    public function __construct(
        private readonly CompilationValidator $validator = new CompilationValidator,
    ) {}

    /**
     * @param  list<Manifest>  $manifests
     * @param  list<string>|null  $installedPackages  composer names known installed beyond
     *                                                $manifests. Defaults to a real read of
     *                                                `vendor/composer/installed.json` ({@see
     *                                                InstalledPackages}) — pass an explicit list in
     *                                                tests/testbench to avoid depending on the host's
     *                                                installed.json contents.
     * @param  (callable(class-string): void)|null  $onStage  observes each {@see PipelineStages}
     *                                                        entry as its stage starts, in the order
     *                                                        actually executed — test seam for
     *                                                        pipeline determinism.
     *
     * @internal spec: AC-2, AC-18
     */
    public function compile(array $manifests, ?array $installedPackages = null, ?callable $onStage = null): CompiledRegistry
    {
        $this->validator->validateRequiredFields($manifests);
        $this->validator->validateExtenderSet($manifests);
        $this->validator->validateTablePrefixes($manifests);
        $this->validator->validateRequires($manifests, $installedPackages ?? InstalledPackages::discover());
        $this->validator->validateComponents($manifests);
        $this->validator->validateDependencyGraph($manifests);

        $entities = [];
        $entityFields = [];
        $permissions = [];
        $api = [];
        $filament = [];
        $menu = [];
        $routes = [];
        $workflow = [];

        foreach (PipelineStages::ORDER as $stage) {
            if ($onStage !== null) {
                $onStage($stage);
            }

            match ($stage) {
                Entity::class => $entities = $this->applyEntityExtenders($manifests),
                EntityField::class => $entityFields = $this->applyEntityFieldExtenders($manifests, $entities),
                Permissions::class => $permissions = $this->collectPermissions($manifests),
                Api::class => $api = $this->collectApi($manifests),
                Filament::class => $filament = $this->collectFilament($manifests),
                Menu::class => $menu = $this->collectMenu($manifests),
                Routes::class => $routes = $this->collectRoutes($manifests),
                Workflow::class => $workflow = $this->collectWorkflow($manifests),
            };
        }

        return new CompiledRegistry(
            entities: $entities,
            entityFields: $entityFields,
            permissions: $permissions,
            api: $api,
            filament: $filament,
            menu: $menu,
            routes: $routes,
            workflow: $workflow,
            settings: $this->collectSettings($manifests),
            featureFlags: $this->collectFeatureFlags($manifests),
            listeners: array_column(array_map(static fn (Manifest $manifest): array => [
                'module' => $manifest->composerName, 'listeners' => $manifest->listeners,
            ], $manifests), 'listeners', 'module'),
        );
    }

    /**
     * `Manifest::$settings`/`$featureFlags` are plain manifest properties,
     * not entries in the closed 8-extender pipeline — they never reached
     * the compiled file before this — collected here directly, outside
     * {@see PipelineStages::ORDER}.
     *
     * @param  list<Manifest>  $manifests
     * @return list<CompiledSettingDefault>
     *
     * @internal spec: P1.25, A68
     */
    private function collectSettings(array $manifests): array
    {
        $settings = [];

        foreach ($manifests as $manifest) {
            // Guarded by CompilationValidator::validateRequiredFields()
            // (tablePrefix is mandatory) before this method ever runs — see
            // compileEntity()'s identical guard. A DISTINCT throw: reaching
            // it means validation was bypassed, not that the manifest is bad.
            $namespace = $manifest->tablePrefix ?? throw CompilationException::tablePrefixInvariantBypassed($manifest->composerName);

            foreach ($manifest->settings as $default) {
                $settings[] = new CompiledSettingDefault($namespace, $default);
            }
        }

        return $settings;
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<FeatureFlagDefinition>
     */
    private function collectFeatureFlags(array $manifests): array
    {
        $flags = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->featureFlags as $flag) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return array<string, EntityDefinition>
     */
    private function applyEntityExtenders(array $manifests): array
    {
        $entities = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof Entity) {
                    continue;
                }

                $definition = $this->compileEntity($manifest, $extender);

                if (isset($entities[$definition->handle])) {
                    throw CompilationException::duplicateEntityHandle(
                        $definition->handle,
                        $entities[$definition->handle]->module,
                        $manifest->composerName,
                    );
                }

                $entities[$definition->handle] = $definition;
            }
        }

        return $entities;
    }

    private function compileEntity(Manifest $manifest, Entity $extender): EntityDefinition
    {
        $modulePrefix = $manifest->tablePrefix;

        if ($modulePrefix === null) {
            // Guarded by CompilationValidator::validateRequiredFields() before the pipeline
            // starts (tablePrefix is mandatory, no auto-derivation from the composer
            // name); this branch only matters if compileEntity() is ever reached directly.
            // Distinct throw: a validation bypass, not an ordinary rejection.
            throw CompilationException::tablePrefixInvariantBypassed($manifest->composerName);
        }

        $handle = $extender->handle ?? sprintf('%s.%s', $modulePrefix, Str::snake(class_basename($extender->model)));
        $table = $extender->table ?? $modulePrefix.'_'.Str::snake(Str::plural(class_basename($extender->model)));

        if (! str_starts_with($table, $modulePrefix.'_')) {
            throw CompilationException::tablePrefixMismatch($manifest->composerName, $table, $modulePrefix);
        }

        $labelSingular = $extender->labelSingular ?? Str::headline(class_basename($extender->model));

        return new EntityDefinition(
            handle: $handle,
            model: $extender->model,
            table: $table,
            module: $manifest->composerName,
            labelSingular: $labelSingular,
            labelPlural: $extender->labelPlural ?? Str::plural($labelSingular),
            capabilities: $this->capabilitiesFor($extender),
            fields: $extender->fieldDefinitions ?? $this->introspectCasts($extender->model),
            titleAttribute: $extender->titleAttribute,
        );
    }

    /** @return list<Capability> */
    private function capabilitiesFor(Entity $extender): array
    {
        $capabilities = [];

        if ($extender->isSearchable) {
            $capabilities[] = Capability::Searchable;
        }

        if ($extender->isAuditable) {
            $capabilities[] = Capability::Auditable;
        }

        if ($extender->hasCustomFields) {
            $capabilities[] = Capability::CustomFields;
        }

        if ($extender->isWorkflowable) {
            $capabilities[] = Capability::Workflowable;
        }

        if ($extender->isCommentable) {
            $capabilities[] = Capability::Commentable;
        }

        if ($extender->isDocumentable) {
            $capabilities[] = Capability::Documentable;
        }

        return $capabilities;
    }

    /**
     * Falls back to introspecting the model's `$casts` when the extender
     * doesn't declare fields explicitly.
     *
     * @param  class-string<Model>  $model
     * @return list<FieldDefinition>
     *
     * @internal spec: B-10 §4.2
     */
    private function introspectCasts(string $model): array
    {
        $instance = new $model;

        $fields = [];

        foreach ($instance->getCasts() as $attribute => $cast) {
            $fields[] = new FieldDefinition(handle: $attribute, type: $this->mapCastType($cast));
        }

        return $fields;
    }

    /** @return 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum' */
    private function mapCastType(string $cast): string
    {
        return match (true) {
            $cast === MoneyCast::class || str_starts_with($cast, MoneyCast::class.':') => 'money',
            in_array($cast, ['int', 'integer'], true) => 'int',
            str_starts_with($cast, 'decimal:') => 'decimal',
            in_array($cast, ['bool', 'boolean'], true) => 'bool',
            $cast === 'date' => 'date',
            str_starts_with($cast, 'datetime') || str_starts_with($cast, 'immutable_datetime') => 'datetime',
            in_array($cast, ['array', 'json', 'collection', 'object'], true) => 'json',
            default => 'string',
        };
    }

    /**
     * @param  list<Manifest>  $manifests
     * @param  array<string, EntityDefinition>  $entities
     * @return list<CompiledEntityField>
     */
    private function applyEntityFieldExtenders(array $manifests, array $entities): array
    {
        $compiled = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof EntityField) {
                    continue;
                }

                $target = $entities[$extender->entityHandle] ?? null;
                $this->validator->validateEntityHandleKnown($manifest->composerName, $extender->entityHandle, $target !== null);

                /** @var EntityDefinition $target */
                $compiled[] = new CompiledEntityField(
                    extender: $extender,
                    declaringModule: $manifest->composerName,
                    sleeping: $target->module !== $manifest->composerName,
                );
            }
        }

        return $compiled;
    }

    /**
     * @template T of Extender
     *
     * @param  list<Manifest>  $manifests
     * @param  class-string<T>  $extenderClass
     * @return list<T>
     */
    private function collect(array $manifests, string $extenderClass): array
    {
        $result = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if ($extender instanceof $extenderClass) {
                    $result[] = $extender;
                }
            }
        }

        return $result;
    }

    /**
     * Concretely-typed wrappers around {@see collect()} (one per remaining
     * extender kind, PipelineStages::ORDER slots 3–8). Template inference on
     * a direct `collect($manifests, X::class)` call loses T when the call
     * site sits inside a `match` arm; a named method with its own concrete
     * `@return` gives the match arm a type PHPStan doesn't have to re-derive.
     *
     * @param  list<Manifest>  $manifests
     * @return list<Permissions>
     */
    private function collectPermissions(array $manifests): array
    {
        return $this->collect($manifests, Permissions::class);
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<Api>
     */
    private function collectApi(array $manifests): array
    {
        $result = [];
        $ids = [];
        $paths = [];
        $operations = [];
        $operationsByModule = [];
        $toolTuples = [];
        $wireNames = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $extender instanceof Api) {
                    continue;
                }

                $api = clone $extender;
                // Provenance comes from the owning manifest, never a caller-supplied property.
                $api->declaringModule = $manifest->composerName;

                if ($api->mode === 'operation') {
                    ApiOperationValidator::validate($api);
                    $id = $api->version.':'.$api->operationId;
                    $path = $api->version.':'.$api->method.':'.preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '{}', $api->path);

                    if (isset($ids[$id]) || isset($paths[$path])) {
                        throw new CompilationException('Duplicate API operation identifier or route: '.$id);
                    }

                    foreach ($operations as $other) {
                        ApiOperationValidator::validateRoutePair($api, $other);
                    }

                    $ids[$id] = true;
                    $paths[$path] = true;
                    $operations[] = $api;
                    $operationsByModule[$manifest->composerName][$id] = $api;
                }

                $result[] = $api;
            }
        }

        foreach ($result as $api) {
            if ($api->mode !== 'tool') {
                continue;
            }

            ApiToolValidator::validate($api);

            if ($api->toolKind === 'exposed') {
                $operation = $operationsByModule[$api->declaringModule][$api->operationRef] ?? null;

                if (! $operation instanceof Api) {
                    throw new CompilationException('Unresolved operation reference for tool: '.$api->toolCode);
                }

                ApiToolValidator::validateExposedOperationReference($api, $operation, $api->declaringModule);
            }

            $tuple = $api->declaringModule.':'.$api->toolCode.':'.$api->toolVersion;

            if (isset($toolTuples[$tuple])) {
                throw new CompilationException('Duplicate tool tuple: '.$tuple);
            }

            $wire = ApiToolValidator::wireName($api);

            if (isset($wireNames[$wire])) {
                throw new CompilationException('Duplicate tool wire name: '.$wire);
            }

            $toolTuples[$tuple] = true;
            $wireNames[$wire] = true;
        }

        return $result;
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<Filament>
     */
    private function collectFilament(array $manifests): array
    {
        return $this->collect($manifests, Filament::class);
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<Menu>
     */
    private function collectMenu(array $manifests): array
    {
        return $this->collect($manifests, Menu::class);
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<Routes>
     */
    private function collectRoutes(array $manifests): array
    {
        return $this->collect($manifests, Routes::class);
    }

    /**
     * @param  list<Manifest>  $manifests
     * @return list<Workflow>
     */
    private function collectWorkflow(array $manifests): array
    {
        return $this->collect($manifests, Workflow::class);
    }
}
