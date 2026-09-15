<?php

declare(strict_types=1);

namespace CoreX\Modules\Lifecycle;

use CoreX\Modules\Contracts\ModuleStateReader;
use CoreX\Modules\Enums\ModuleState;
use CoreX\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * DB-backed {@see ModuleStateReader}: reads the enabled set straight from
 * `mod_modules`. `$ctx` is unused for now — the current phase has one
 * physical database per install (boxed or single-tenant); a later
 * per-account DB-per-account model (corex/tenancy) will give `$ctx`
 * meaning. `$connection` is null (app default) unless a caller pins an
 * explicit name (tests).
 *
 * @internal spec: P1.5, D13
 */
final class DatabaseModuleStateReader implements ModuleStateReader
{
    public function __construct(private readonly ?string $connection = null) {}

    /** @return array<string, array{state: string, edition: string}> */
    public function enabledFor(TenantContext $ctx): array
    {
        return DB::connection($this->connection)->table('mod_modules')
            ->where('state', ModuleState::Enabled->value)
            ->get(['name', 'state', 'edition'])
            ->mapWithKeys(static fn (object $row): array => [
                $row->name => ['state' => $row->state, 'edition' => $row->edition],
            ])
            ->all();
    }
}
