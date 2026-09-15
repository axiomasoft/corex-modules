<?php

declare(strict_types=1);

namespace CoreX\Modules\Registry;

use Closure;
use CoreX\Modules\Contracts\EntityRegistry;
use CoreX\Modules\Enums\Capability;
use CoreX\Modules\Exceptions\EntityNotFound;
use CoreX\Tenancy\TenantContext;

/**
 * In-memory EntityRegistry. Module providers register() their entities at
 * boot; the compiled hot-path registry replaces this for production reads
 * once RegistryCompiler exists.
 *
 * @internal spec: P1.5, P1.4
 */
final class ArrayEntityRegistry implements EntityRegistry
{
    /** @var array<string, EntityDefinition> */
    private array $entities = [];

    /** @var Closure(string, TenantContext): bool */
    private readonly Closure $moduleActive;

    /**
     * @param  (Closure(string, TenantContext): bool)|null  $moduleActive  Real active-module
     *                                                                     predicate (ModuleRegistry::isActive).
     *                                                                     Takes both the module composer name AND the
     *                                                                     tenant ctx — activity is per-account.
     * @param  (Closure(TenantContext): list<string>)|null  $activeModules  One active-module snapshot per list operation.
     *
     * @internal spec: P1.16, A38
     */
    public function __construct(?Closure $moduleActive = null, private readonly ?Closure $activeModules = null)
    {
        $this->moduleActive = $moduleActive ?? static fn (string $module, TenantContext $ctx): bool => true;
    }

    public function register(EntityDefinition $definition): void
    {
        $this->entities[$definition->handle] = $definition;
    }

    public function get(string $handle): EntityDefinition
    {
        return $this->entities[$handle] ?? throw new EntityNotFound($handle);
    }

    public function has(string $handle): bool
    {
        return isset($this->entities[$handle]);
    }

    public function all(?TenantContext $ctx = null): array
    {
        // No ctx = the WHOLE registry: the morph map and satellite
        // wiring span every declared entity, including those of currently
        // disabled modules — a persisted row of a disabled module must still
        // resolve its morph alias. The active-module filter only applies when
        // a ctx is given (per-account activity).
        if ($ctx === null) {
            return array_values($this->entities);
        }

        $active = $this->activeModules === null ? null : array_fill_keys(($this->activeModules)($ctx), true);
        $memo = [];

        return array_values(array_filter(
            $this->entities,
            function (EntityDefinition $definition) use ($ctx, $active, &$memo): bool {
                return $active !== null
                    ? isset($active[$definition->module])
                    : ($memo[$definition->module] ??= ($this->moduleActive)($definition->module, $ctx));
            },
        ));
    }

    public function byCapability(Capability $cap, ?TenantContext $ctx = null): array
    {
        return array_values(array_filter(
            $this->all($ctx),
            fn (EntityDefinition $definition): bool => in_array($cap, $definition->capabilities, true),
        ));
    }

    public function forModel(string $modelClass): ?EntityDefinition
    {
        foreach ($this->entities as $definition) {
            if ($definition->model === $modelClass) {
                return $definition;
            }
        }

        return null;
    }
}
