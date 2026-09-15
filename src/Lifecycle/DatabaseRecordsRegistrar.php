<?php

declare(strict_types=1);

namespace CoreX\Modules\Lifecycle;

use CoreX\Modules\Contracts\RecordsRegistrar;
use Illuminate\Support\Facades\DB;

/**
 * DB-backed {@see RecordsRegistrar} over `mod_records`. `$connection` is
 * null (the app's default — correct in production) unless a caller pins an
 * explicit name (tests — see DatabaseModuleLifecycle's constructor
 * docblock for why).
 *
 * @internal spec: D12
 */
final class DatabaseRecordsRegistrar implements RecordsRegistrar
{
    public function __construct(private readonly ?string $connection = null) {}

    /** @param  array<string, mixed>  $meta */
    public function claim(string $module, string $recordType, string $reference, array $meta = []): void
    {
        // created_at is an informational wall-clock stamp only — purge orders
        // by the monotonic bigserial `seq` (auto-assigned on insert), not
        // by this column, so two claims in the same second stay correctly
        // orderable regardless of clock precision.
        $now = now()->format('Y-m-d H:i:s.u');
        $identity = ['module_name' => $module, 'record_type' => $recordType, 'reference' => $reference];
        $table = DB::connection($this->connection)->table('mod_records');

        // Re-claiming is idempotent (EnableHook retries): update meta in
        // place, keep the original created_at so purge order stays stable.
        if ($table->where($identity)->exists()) {
            $table->where($identity)->update([
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            return;
        }

        $table->insert([
            ...$identity,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function release(string $module, string $recordType, string $reference): void
    {
        DB::connection($this->connection)->table('mod_records')
            ->where('module_name', $module)
            ->where('record_type', $recordType)
            ->where('reference', $reference)
            ->delete();
    }
}
