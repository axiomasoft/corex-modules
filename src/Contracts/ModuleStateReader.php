<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use CoreX\Tenancy\TenantContext;

/**
 * Seam separating {@see ModuleRegistry}'s hot path from the database (this
 * package stays PG-free). Returns the enabled set for one tenant, keyed by
 * module composer name.
 */
interface ModuleStateReader
{
    /** @return array<string, array{state: string, edition: string}> */
    public function enabledFor(TenantContext $ctx): array;
}
