<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P2.11 (D138) — mod_lifecycle_log.actor_ck holds a DIFFERENT vocabulary
// than sys_audit_log (no agent/api_key here) and DatabaseModuleLifecycle
// writes CurrentActor::type()->value into it; without this widening, the
// first lifecycle operation performed under impersonation would fail with a
// CHECK-violation. Additive — the closed P1.3 migration is never edited.
// down() restores the ORIGINAL four-value list.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE mod_lifecycle_log DROP CONSTRAINT mod_ll_actor_ck');
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_ll_actor_ck CHECK (actor_type IN ('user','system','platform','cli','support'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE mod_lifecycle_log DROP CONSTRAINT mod_ll_actor_ck');
        DB::statement("ALTER TABLE mod_lifecycle_log ADD CONSTRAINT mod_ll_actor_ck CHECK (actor_type IN ('user','system','platform','cli'))");
    }
};
