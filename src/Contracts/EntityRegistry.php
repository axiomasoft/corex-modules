<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use CoreX\Modules\Enums\Capability;
use CoreX\Modules\Exceptions\EntityNotFound;
use CoreX\Modules\Registry\EntityDefinition;
use CoreX\Tenancy\TenantContext;

/**
 * Central registry of ecosystem entities. Single source of the polymorphic
 * morph map (ADR-003) and the contract satellites (search, audit,
 * attributes, workflow, comments, documents) query via byCapability() to
 * wire themselves without knowing the owning module.
 *
 * @internal spec: B-10 §3.4
 */
interface EntityRegistry
{
    /** @throws EntityNotFound */
    public function get(string $handle): EntityDefinition;

    public function has(string $handle): bool;

    /** @return list<EntityDefinition> active for the tenant (owning module enabled) */
    public function all(?TenantContext $ctx = null): array;

    /** @return list<EntityDefinition> */
    public function byCapability(Capability $cap, ?TenantContext $ctx = null): array;

    /** @param class-string $modelClass */
    public function forModel(string $modelClass): ?EntityDefinition;
}
