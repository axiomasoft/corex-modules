<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Contracts\FeatureFlagDefinition;
use CoreX\Modules\Extend\Api;
use CoreX\Modules\Extend\Extender;
use CoreX\Modules\Extend\Filament;
use CoreX\Modules\Extend\Menu;
use CoreX\Modules\Extend\Permissions;
use CoreX\Modules\Extend\Routes;
use CoreX\Modules\Extend\Workflow;
use CoreX\Modules\Registry\EntityDefinition;
use ReflectionClass;

/**
 * In-memory result of RegistryCompiler::compile(). The compiled file
 * `bootstrap/cache/corex_modules.php` persists this whole DTO (all 10
 * slices — the original 8 extender slices plus `settings`/`featureFlags`)
 * via {@see toArray()}/{@see fromArray()} so the runtime can project
 * entities into EntityRegistry and the morph map without re-running the
 * compiler on every request.
 *
 * @internal spec: B-10 §5.2, P1.25, P1.15
 */
final readonly class CompiledRegistry
{
    /**
     * @param  array<string, EntityDefinition>  $entities  keyed by handle
     * @param  list<CompiledEntityField>  $entityFields
     * @param  list<Permissions>  $permissions
     * @param  list<Api>  $api
     * @param  list<Filament>  $filament
     * @param  list<Menu>  $menu
     * @param  list<Routes>  $routes
     * @param  list<Workflow>  $workflow
     * @param  list<CompiledSettingDefault>  $settings  manifest-declared `SettingDefault`s (default cascade level, not an Extender, so collected straight off `Manifest::$settings`)
     * @param  list<FeatureFlagDefinition>  $featureFlags  manifest-declared flag defaults, collected off `Manifest::$featureFlags`
     * @param  array<string, array<class-string, list<class-string>>>  $listeners  manifest-declared event listeners, keyed by module composer name
     *
     * @internal spec: P1.25, B-10 §3.1
     */
    public function __construct(
        public array $entities,
        public array $entityFields,
        public array $permissions,
        public array $api,
        public array $filament,
        public array $menu,
        public array $routes,
        public array $workflow,
        public array $settings = [],
        public array $featureFlags = [],
        public array $listeners = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            entities: [],
            entityFields: [],
            permissions: [],
            api: [],
            filament: [],
            menu: [],
            routes: [],
            workflow: [],
            settings: [],
            featureFlags: [],
            listeners: [],
        );
    }

    /**
     * Plain-array projection of all 10 slices for the compiled file. Entity
     * definitions keep their handle keys; the six extender slices flatten to
     * their public scalar properties via {@see extenderToArray()} — the
     * concrete extender class per slice is fixed, so {@see fromArray()} knows
     * exactly which class to rehydrate without a discriminator.
     *
     * @return array{entities: array<string, array<string, mixed>>, entityFields: list<array<string, mixed>>, permissions: list<array<string, mixed>>, api: list<array<string, mixed>>, filament: list<array<string, mixed>>, menu: list<array<string, mixed>>, routes: list<array<string, mixed>>, workflow: list<array<string, mixed>>, settings: list<array<string, mixed>>, featureFlags: list<array<string, mixed>>, listeners: array<string, array<class-string, list<class-string>>>}
     */
    public function toArray(): array
    {
        return [
            'entities' => array_map(static fn (EntityDefinition $entity): array => $entity->toArray(), $this->entities),
            'entityFields' => array_map(static fn (CompiledEntityField $field): array => $field->toArray(), $this->entityFields),
            'permissions' => array_map(self::extenderToArray(...), $this->permissions),
            'api' => array_map(self::extenderToArray(...), $this->api),
            'filament' => array_map(self::extenderToArray(...), $this->filament),
            'menu' => array_map(self::extenderToArray(...), $this->menu),
            'routes' => array_map(self::extenderToArray(...), $this->routes),
            'workflow' => array_map(self::extenderToArray(...), $this->workflow),
            'settings' => array_map(static fn (CompiledSettingDefault $setting): array => $setting->toArray(), $this->settings),
            'featureFlags' => array_map(static fn (FeatureFlagDefinition $flag): array => get_object_vars($flag), $this->featureFlags),
            'listeners' => $this->listeners,
        ];
    }

    /**
     * @param  array{entities?: array<string, array<string, mixed>>, entityFields?: list<array<string, mixed>>, permissions?: list<array<string, mixed>>, api?: list<array<string, mixed>>, filament?: list<array<string, mixed>>, menu?: list<array<string, mixed>>, routes?: list<array<string, mixed>>, workflow?: list<array<string, mixed>>, settings?: list<array<string, mixed>>, featureFlags?: list<array<string, mixed>>, listeners?: array<string, array<class-string, list<class-string>>>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            entities: array_map(
                static fn (array $entity): EntityDefinition => EntityDefinition::fromArray($entity),
                $data['entities'] ?? [],
            ),
            entityFields: array_map(
                static fn (array $field): CompiledEntityField => CompiledEntityField::fromArray($field),
                $data['entityFields'] ?? [],
            ),
            permissions: array_map(static fn (array $row): Permissions => self::extenderFromArray(Permissions::class, $row), $data['permissions'] ?? []),
            api: array_map(static fn (array $row): Api => self::extenderFromArray(Api::class, $row), $data['api'] ?? []),
            filament: array_map(static fn (array $row): Filament => self::extenderFromArray(Filament::class, $row), $data['filament'] ?? []),
            menu: array_map(static fn (array $row): Menu => self::extenderFromArray(Menu::class, $row), $data['menu'] ?? []),
            routes: array_map(static fn (array $row): Routes => self::extenderFromArray(Routes::class, $row), $data['routes'] ?? []),
            workflow: array_map(static fn (array $row): Workflow => self::extenderFromArray(Workflow::class, $row), $data['workflow'] ?? []),
            settings: array_map(
                /** @param  array{namespace: string, definition: array<string, mixed>}  $setting */
                static fn (array $setting): CompiledSettingDefault => CompiledSettingDefault::fromArray($setting),
                $data['settings'] ?? [],
            ),
            featureFlags: array_map(self::featureFlagFromArray(...), $data['featureFlags'] ?? []),
            listeners: $data['listeners'] ?? [],
        );
    }

    /** @param  array{key: string, default: bool, module: string|null, payload: array<string, mixed>|null}  $data */
    private static function featureFlagFromArray(array $data): FeatureFlagDefinition
    {
        return new FeatureFlagDefinition(
            key: $data['key'],
            default: $data['default'],
            module: $data['module'],
            payload: $data['payload'],
        );
    }

    /**
     * Flatten an extender to its public scalar/array properties. Every
     * extender in the closed set holds only scalars and arrays-of-scalars
     * (class-strings stay strings) — none nest objects — so
     * `get_object_vars()` yields a var_export'able array with no
     * `__set_state` and no closures. Shared by {@see CompiledEntityField}.
     *
     * @return array<string, mixed>
     *
     * @internal spec: B-10 §4.2
     */
    public static function extenderToArray(Extender $extender): array
    {
        return get_object_vars($extender);
    }

    /**
     * Rebuild an extender from its flattened array. Reconstruction is by
     * property assignment (not the private factory methods, which the runtime
     * has no data to feed) — the inverse of {@see extenderToArray()}, and the
     * only reason `__set_state` is not needed in the persisted file.
     *
     * @template T of Extender
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $data
     * @return T
     */
    public static function extenderFromArray(string $class, array $data): Extender
    {
        $reflection = new ReflectionClass($class);
        $extender = $reflection->newInstanceWithoutConstructor();

        foreach ($data as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($extender, $value);
            }
        }

        return $extender;
    }
}
