<?php

declare(strict_types=1);

namespace CoreX\Modules\Internal;

use CoreX\Modules\Compiler\CompiledEntityField;
use CoreX\Modules\Compiler\CompiledRegistry;
use CoreX\Modules\Compiler\InstalledPackages;
use CoreX\Modules\Compiler\PipelineStages;
use CoreX\Modules\Contracts\ModuleActivationGate;
use CoreX\Modules\Contracts\ModuleRegistry;
use CoreX\Modules\Contracts\RegistryIntrospection;
use CoreX\Modules\Data\ModuleExplanation;
use CoreX\Modules\Data\RegistryMap;
use CoreX\Modules\Exceptions\InvalidRegistryPoint;
use CoreX\Modules\Exceptions\ModuleNotFound;
use CoreX\Modules\Exceptions\RegistryInputUnavailable;
use CoreX\Modules\Exceptions\StaleRegistryRevision;
use CoreX\Modules\Extend\Api;
use CoreX\Modules\Extend\Entity;
use CoreX\Modules\Extend\EntityField;
use CoreX\Modules\Extend\Filament;
use CoreX\Modules\Extend\Menu;
use CoreX\Modules\Extend\Permissions;
use CoreX\Modules\Extend\Routes;
use CoreX\Modules\Extend\Workflow;
use CoreX\Modules\Registry\CompiledModule;
use CoreX\Modules\Registry\EntityDefinition;
use CoreX\Tenancy\TenantContext;
use Override;

/**
 * Read-only registry diagnostics over compiled module bytes and optional
 * tenant activation. Redacts internal class references and connection detail.
 *
 * @internal
 */
final class ReadRegistryIntrospection implements RegistryIntrospection
{
    public const string MAP_SCHEMA = 'registry-map/1';

    public const string EXPLANATION_SCHEMA = 'module-explanation/1';

    /** @var array<class-string, string> */
    private const EXTENDER_NAMES = [
        Entity::class => 'entity',
        EntityField::class => 'entity_field',
        Permissions::class => 'permissions',
        Api::class => 'api',
        Filament::class => 'filament',
        Menu::class => 'menu',
        Routes::class => 'routes',
        Workflow::class => 'workflow',
    ];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleActivationGate $activationGate,
        private readonly string $cachePath,
        private readonly bool $failIfMissing = false,
    ) {}

    #[Override]
    public function extensionPoints(): RegistryMap
    {
        $compiled = $this->compiledRegistry();
        $revision = self::revisionFor($compiled);

        return new RegistryMap(
            schemaVersion: self::MAP_SCHEMA,
            revision: $revision,
            extensionPoints: $this->buildExtensionPoints($compiled),
        );
    }

    #[Override]
    public function why(string $module, ?TenantContext $context): ModuleExplanation
    {
        $this->assertModuleName($module);

        $modules = $this->moduleIndex();
        $compiledModule = $modules[$module] ?? null;

        if ($compiledModule === null) {
            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'unknown',
                declaredConstraints: [],
                reasons: ['module_unknown'],
                hasTenantVerdict: false,
            );
        }

        $constraints = $this->declaredConstraints($compiledModule);
        $reasons = [];

        if (! $this->dependenciesSatisfied($compiledModule, $modules)) {
            $reasons[] = 'missing_dependency';
        }

        if ($context === null) {
            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: $reasons === [] ? 'declared' : 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons === [] ? ['static_declaration_only'] : $reasons,
                hasTenantVerdict: false,
            );
        }

        $active = $this->registry->isActive($module, $context);

        if (! $active) {
            if ($reasons === []) {
                $reasons[] = 'not_enabled_for_tenant';
            }

            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons,
                hasTenantVerdict: true,
            );
        }

        $edition = $this->resolveEdition($compiledModule);
        $gate = $this->activationGate->check($context, $module, $edition);

        if (! $gate->allowed) {
            $reasons[] = 'activation_gate_denied';

            return new ModuleExplanation(
                schemaVersion: self::EXPLANATION_SCHEMA,
                module: $module,
                status: 'inactive',
                declaredConstraints: $constraints,
                reasons: $reasons,
                hasTenantVerdict: true,
            );
        }

        return new ModuleExplanation(
            schemaVersion: self::EXPLANATION_SCHEMA,
            module: $module,
            status: 'active',
            declaredConstraints: $constraints,
            reasons: ['enabled_for_tenant'],
            hasTenantVerdict: true,
        );
    }

    public static function assertRevision(RegistryMap $map, string $expectedRevision): void
    {
        if ($map->revision !== $expectedRevision) {
            throw StaleRegistryRevision::mismatch($expectedRevision, $map->revision);
        }
    }

    public static function assertExtensionPoint(string $name): void
    {
        if (! in_array($name, self::knownExtensionPointNames(), true)) {
            throw InvalidRegistryPoint::unknown($name);
        }
    }

    /** @return list<string> */
    public static function knownExtensionPointNames(): array
    {
        return array_values(self::EXTENDER_NAMES);
    }

    public static function revisionFor(CompiledRegistry $compiled): string
    {
        $payload = json_encode($compiled->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $payload);
    }

    private function compiledRegistry(): CompiledRegistry
    {
        $this->assertRegistryInputAvailable();

        return $this->registry->compiled();
    }

    /** @return array<string, CompiledModule> */
    private function moduleIndex(): array
    {
        $this->assertRegistryInputAvailable();

        $index = [];

        foreach ($this->registry->all() as $module) {
            $index[$module->composerName] = $module;
        }

        return $index;
    }

    private function assertRegistryInputAvailable(): void
    {
        if (! is_file($this->cachePath)) {
            if ($this->failIfMissing) {
                throw RegistryInputUnavailable::missing($this->cachePath);
            }

            if ($this->registry->all() === []) {
                throw RegistryInputUnavailable::missing($this->cachePath);
            }

            return;
        }

        /** @var mixed $payload */
        $payload = require $this->cachePath;

        if (! is_array($payload)) {
            throw RegistryInputUnavailable::corrupt();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildExtensionPoints(CompiledRegistry $compiled): array
    {
        $entityOwners = $this->ownersFromEntities($compiled->entities);
        $fieldOwners = $this->ownersFromEntityFields($compiled->entityFields);
        $apiOwners = $this->ownersFromApi($compiled->api);
        $permissionOwners = $this->ownersFromPermissions($compiled->permissions, $compiled->entities);
        $filamentOwners = $this->ownersFromFilament($compiled->filament, $compiled->entities);
        $workflowOwners = $this->ownersFromWorkflow($compiled->workflow, $compiled->entities);

        $points = [];

        foreach (PipelineStages::ORDER as $extenderClass) {
            $name = self::EXTENDER_NAMES[$extenderClass];

            $owners = match ($name) {
                'entity' => $entityOwners,
                'entity_field' => $fieldOwners,
                'permissions' => $permissionOwners,
                'api' => $apiOwners,
                'filament' => $filamentOwners,
                'workflow' => $workflowOwners,
                default => $this->ownersByCount($this->sliceCount($compiled, $name)),
            };

            $points[] = [
                'kind' => 'extender',
                'name' => $name,
                'entryCount' => array_sum($owners),
                'owners' => $owners,
            ];
        }

        foreach ($compiled->entities as $entity) {
            $points[] = [
                'kind' => 'entity',
                'handle' => $entity->handle,
                'module' => $entity->module,
                'capabilities' => array_map(static fn ($capability): string => $capability->value, $entity->capabilities),
            ];
        }

        foreach ($compiled->entityFields as $field) {
            $points[] = [
                'kind' => 'entity_field',
                'entityHandle' => $field->extender->entityHandle,
                'module' => $field->declaringModule,
                'sleeping' => $field->sleeping,
            ];
        }

        return $points;
    }

    /**
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromEntities(array $entities): array
    {
        $owners = [];

        foreach ($entities as $entity) {
            $owners[$entity->module] = ($owners[$entity->module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<CompiledEntityField>  $fields
     * @return array<string, int>
     */
    private function ownersFromEntityFields(array $fields): array
    {
        $owners = [];

        foreach ($fields as $field) {
            $owners[$field->declaringModule] = ($owners[$field->declaringModule] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Api>  $api
     * @return array<string, int>
     */
    private function ownersFromApi(array $api): array
    {
        $owners = [];

        foreach ($api as $declaration) {
            $module = $declaration->declaringModule ?? 'unknown';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Permissions>  $permissions
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromPermissions(array $permissions, array $entities): array
    {
        $owners = [];

        foreach ($permissions as $permission) {
            $module = $entities[$permission->entity]->module ?? 'unknown';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Filament>  $filament
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromFilament(array $filament, array $entities): array
    {
        $owners = [];

        foreach ($filament as $entry) {
            $module = $entry->entityHandle !== null
                ? ($entities[$entry->entityHandle]->module ?? 'unknown')
                : 'unattributed';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /**
     * @param  list<Workflow>  $workflow
     * @param  array<string, EntityDefinition>  $entities
     * @return array<string, int>
     */
    private function ownersFromWorkflow(array $workflow, array $entities): array
    {
        $owners = [];

        foreach ($workflow as $entry) {
            $module = $entry->entityHandle !== null
                ? ($entities[$entry->entityHandle]->module ?? 'unknown')
                : 'unattributed';
            $owners[$module] = ($owners[$module] ?? 0) + 1;
        }

        ksort($owners);

        return $owners;
    }

    /** @return array<string, int> */
    private function ownersByCount(int $count): array
    {
        return $count > 0 ? ['compiled' => $count] : [];
    }

    private function sliceCount(CompiledRegistry $compiled, string $name): int
    {
        return match ($name) {
            'entity' => count($compiled->entities),
            'entity_field' => count($compiled->entityFields),
            'permissions' => count($compiled->permissions),
            'api' => count($compiled->api),
            'filament' => count($compiled->filament),
            'menu' => count($compiled->menu),
            'routes' => count($compiled->routes),
            'workflow' => count($compiled->workflow),
            default => 0,
        };
    }

    private function assertModuleName(string $module): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $module)) {
            throw new ModuleNotFound($module);
        }
    }

    /** @return array<string, mixed> */
    private function declaredConstraints(CompiledModule $module): array
    {
        return [
            'requires' => $module->requires,
            'editions' => $module->editions,
            'components' => $module->components,
            'compatibilityPassport' => 'pending',
            'installedPackagePresent' => in_array($module->composerName, InstalledPackages::discover(), true),
        ];
    }

    /**
     * @param  array<string, CompiledModule>  $modules
     */
    private function dependenciesSatisfied(CompiledModule $module, array $modules): bool
    {
        $installed = InstalledPackages::discover();

        foreach ($module->requires as $requirement) {
            if ($requirement['type'] !== 'module') {
                if (! in_array($requirement['target'], $installed, true)) {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($requirement['target'], $modules)) {
                return false;
            }
        }

        return true;
    }

    private function resolveEdition(CompiledModule $module): string
    {
        $editions = array_keys($module->editions);

        return $editions[0] ?? 'default';
    }
}
