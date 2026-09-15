<?php

declare(strict_types=1);

namespace CoreX\Modules\Registry;

use CoreX\Modules\Contracts\ModuleStateReader;
use CoreX\Tenancy\TenantContext;

/**
 * Fake reader for tests and the boxed default — no database. The
 * DB-backed reader (`SELECT name,state,edition FROM mod_modules`) is the
 * production counterpart.
 *
 * @internal spec: P1.5, P1.6
 */
final class ArrayModuleStateReader implements ModuleStateReader
{
    /**
     * @param  array<string, array<string, array{state: string, edition: string}>>  $statesByAccount
     *                                                                                                keyed by account id, then module composer name.
     */
    public function __construct(
        private readonly array $statesByAccount = [],
    ) {}

    public function enabledFor(TenantContext $ctx): array
    {
        return $this->statesByAccount[$ctx->account->id] ?? [];
    }
}
