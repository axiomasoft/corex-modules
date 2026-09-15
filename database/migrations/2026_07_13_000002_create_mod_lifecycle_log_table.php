<?php

declare(strict_types=1);

use CoreX\Support\Schema\IdColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Append-style audit of every enable/disable/upgrade/archive/purge — used to
// diagnose partial-failure retries (AC-7/AC-13). Published under
// `corex-modules-migrations`/`corex-modules-migrations-tenant` (D33/D43).
// id/actor_id follow config('corex.ids.strategy') via IdColumns (D3/A58);
// timestampTz precision is explicit µs (D32/A66). DB_SCHEMA.md §3.3
// mod_lifecycle_log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_lifecycle_log', function (Blueprint $table): void {
            IdColumns::primary($table);
            $table->string('module_name', 190);
            $table->string('operation', 16);
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->string('status', 16)->default('success');
            $table->string('actor_type', 16);
            IdColumns::reference($table, 'actor_id')->nullable();
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });

        // Beyond the DRAFT DB_SCHEMA catalog (A91, documented deviation): a
        // lookup index over the append-only log by module + recency. Kept, not
        // dropped — the catalog is silent on it, not in conflict; recorded in
        // the P1.19 column-by-column report.
        DB::statement('CREATE INDEX mod_ll_module_ix ON mod_lifecycle_log (module_name, created_at DESC)');
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_ll_op_ck CHECK (operation IN ('enable','disable','upgrade','archive','purge'))");
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_ll_status_ck CHECK (status IN ('success','failed','started'))");
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_ll_actor_ck CHECK (actor_type IN ('user','system','platform','cli'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_lifecycle_log');
    }
};
