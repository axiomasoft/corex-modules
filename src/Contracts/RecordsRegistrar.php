<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

/**
 * Ownership registry for everything a module created in the current
 * tenant's database (`mod_records` — mirrors Odoo `ir_model_data`). Purge
 * walks this registry in reverse `seq` order (a monotonic bigserial
 * surrogate, not the second-precision clock) — the foundation of a
 * predictable, FK-safe teardown. Schema claims (table/column/index) are
 * written automatically by the lifecycle's migration wrapper; hooks claim
 * logical records (setting/role/menu) explicitly.
 *
 * @internal spec: B-10 §3.3, §5.4
 */
interface RecordsRegistrar
{
    /** @param  array<string, mixed>  $meta */
    public function claim(string $module, string $recordType, string $reference, array $meta = []): void;

    public function release(string $module, string $recordType, string $reference): void;
}
