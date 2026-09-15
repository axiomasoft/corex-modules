<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Ownership registry the FSM purges by (B-10 §5.4) — no FK to mod_modules,
// module_name is a logical key (DB_SCHEMA.md §3.3 mod_records).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_records', function (Blueprint $table): void {
            $table->string('module_name', 190);
            $table->string('record_type', 40);
            $table->string('reference', 255);
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->primary(['module_name', 'record_type', 'reference']);
        });

        // Monotonic surrogate ordering key (D31, Blocker A3). created_at is
        // timestamptz(0) (second precision — RAG §2), so every claim of one
        // enable() collapses onto the same wall-clock second and the drop
        // order would be decided by an alphabetic `reference` tiebreak, not by
        // creation order → a module with a FK between its own tables (parent
        // created before child) drops the parent first and fails 2BP01. `seq`
        // is a clock-free bigserial assigned in insert order (= claim order =
        // creation order via the auto-claim wrapper), so purge orders strictly
        // by `seq DESC` (reverse creation) and is FK-safe. ADD COLUMN keeps the
        // migration additive — the composite PK stays untouched, existing
        // claims keep their rows (Implementation Rules D31; no table recreate).
        DB::statement('ALTER TABLE mod_records ADD COLUMN seq bigserial');

        DB::statement('CREATE INDEX mod_records_module_seq_ix ON mod_records (module_name, seq DESC)');
        DB::statement("ALTER TABLE mod_records ADD CONSTRAINT mod_records_type_ck CHECK (record_type IN ('table','column','index','role','permission','menu','setting','seed','blueprint','feature_flag','other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_records');
    }
};
