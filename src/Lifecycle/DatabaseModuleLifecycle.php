<?php

declare(strict_types=1);

namespace CoreX\Modules\Lifecycle;

use Closure;
use CoreX\Audit\CurrentActor;
use CoreX\Contracts\FeatureFlags;
use CoreX\Modules\Contracts\ModuleActivationGate;
use CoreX\Modules\Contracts\ModuleLifecycle;
use CoreX\Modules\Contracts\ModuleRegistry;
use CoreX\Modules\Contracts\RecordsRegistrar;
use CoreX\Modules\Discovery\ModuleDiscovery;
use CoreX\Modules\Enums\DisableReason;
use CoreX\Modules\Enums\ModuleState;
use CoreX\Modules\Events\ModuleArchived;
use CoreX\Modules\Events\ModuleDisabled;
use CoreX\Modules\Events\ModuleEnabled;
use CoreX\Modules\Events\ModulePurged;
use CoreX\Modules\Events\ModuleUpgraded;
use CoreX\Modules\Exceptions\HasEnabledDependentsException;
use CoreX\Modules\Exceptions\InvalidStateTransitionException;
use CoreX\Modules\Exceptions\MissingDependencyException;
use CoreX\Modules\Exceptions\ModuleEnableFailedException;
use CoreX\Modules\Exceptions\ModuleNotFound;
use CoreX\Modules\Exceptions\ModuleUpgradeFailedException;
use CoreX\Modules\Manifest;
use CoreX\Modules\ModuleContext;
use CoreX\Modules\ModulesServiceProvider;
use CoreX\Modules\ModuleStatus;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use CoreX\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * DB-backed {@see ModuleLifecycle}. Every transition is wrapped in
 * `pg_advisory_xact_lock(hashtext($module))` (xact-scoped, released on
 * commit): a second concurrent caller blocks on the lock, then sees the
 * already-applied state and no-ops.
 *
 * enable() is all-or-nothing: a failing migration or EnableHook rolls
 * the WHOLE transition back (migrations 1..K included — PG DDL is
 * transactional) and rethrows {@see ModuleEnableFailedException}. No partial
 * install, no orphan `mod_modules` row; a fixed retry starts clean.
 *
 * Ownership claims are written AUTOMATICALLY: a per-migration schema
 * diff attributes every table/column/index a module's migrations create to
 * that module ({@see withMigrationClaims()}), so purge can tear them all down
 * in reverse creation order — no hand-written `claim()` calls to drift out of
 * sync with the real schema.
 *
 * `$codeVersions`/`$migrationPaths` (composer name → value) are populated from
 * `vendor/composer/installed.json` by {@see ModulesServiceProvider},
 * so real deploys run each module's migrations; tests inject them directly.
 *
 * @internal spec: B-10 §3.3/§5.1/§5.3/§5.4, B-10 §7.3 п.3, AC-11, D40, AC-7, D41
 */
final class DatabaseModuleLifecycle implements ModuleLifecycle
{
    private const MODULES_TABLE = 'mod_modules';

    private const RECORDS_TABLE = 'mod_records';

    private const LOG_TABLE = 'mod_lifecycle_log';

    private const MIGRATIONS_TABLE = 'migrations';

    /**
     * @param  array<string, string>  $codeVersions
     * @param  array<string, string>  $migrationPaths
     * @param  ?string  $connection  null = the app's default connection (production is PG-only, so that's correct there; tests pin an explicit name to avoid ambient-default mutation).
     *
     * @internal spec: D12
     */
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleDiscovery $discovery,
        private readonly RecordsRegistrar $records,
        private readonly Container $container,
        private readonly TenantContextResolver $tenantContextResolver,
        private readonly array $codeVersions = [],
        private readonly array $migrationPaths = [],
        private readonly ?string $connection = null,
    ) {}

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    /**
     * Microsecond-precision timestamp string. Laravel's query-builder date
     * format is second-precision (Grammar::getDateFormat()) — passing a
     * bare now() Carbon instance as a bound value truncates it. Ordering of
     * claims/log rows no longer relies on this (monotonic surrogates are used
     * instead), but the columns still carry an accurate wall-clock stamp.
     *
     * @internal spec: D31/D42
     */
    private function timestamp(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    public function enable(string $module, string $edition = 'standard', bool $cascade = false): void
    {
        $compiled = $this->registry->get($module); // ModuleNotFound if not a deployed module
        $tenant = $this->tenantContextResolver->current();

        $this->db()->transaction(function () use ($module, $edition, $cascade, $tenant, $compiled): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current !== null && $current->state === ModuleState::Enabled->value) {
                return; // lock serialized us behind the winner — no-op.
            }

            // FSM gate (A11/A12): `purged` is terminal. Its tables are dropped
            // and its migrations are still logged as "ran", so a direct enable
            // would flip the row to enabled over a missing schema. Re-install
            // goes through `corex:modules:repair` → available → enable.
            if ($current !== null && $current->state === ModuleState::Purged->value) {
                throw InvalidStateTransitionException::purgedIsTerminal($module);
            }

            $decision = $this->container->make(ModuleActivationGate::class)->check($tenant, $module, $edition);

            if (! $decision->allowed) {
                throw new AuthorizationException($decision->reason);
            }

            foreach ($compiled->requires as $dep) {
                if ($dep['type'] === 'module') {
                    $this->ensureDependencyEnabled($module, $dep['target'], $cascade);
                }
            }

            $fromState = $current === null ? ModuleState::Available->value : $current->state;
            $context = new ModuleContext($module, $tenant, $this->records);

            $migration = $this->runModuleMigrations($module);

            if ($migration['error'] === null) {
                try {
                    $this->invokeHook($this->manifestFor($module)->onEnableHook, $context);
                } catch (Throwable $e) {
                    $migration['error'] = $e;
                }
            }

            // All-or-nothing: any failure aborts the whole transaction —
            // migrations 1..K, claims and the log row all roll back — and
            // surfaces to the caller. No silent commit, no half-installed row.
            if ($migration['error'] instanceof Throwable) {
                throw new ModuleEnableFailedException($module, $migration['error']);
            }

            $this->upsertModuleRow($module, $current, ModuleState::Enabled, $edition, $migration['ran']);

            $this->defineManifestFeatureFlags($module);

            $this->log($module, 'enable', $fromState, ModuleState::Enabled->value, 'success', [
                'migrations_run' => $migration['ran'],
            ]);

            $this->db()->afterCommit(function () use ($module, $edition, $migration): void {
                event(new ModuleEnabled($module, $edition, $migration['ran']));
            });
        });
    }

    /**
     * `Manifest::$featureFlags` → `FeatureFlags::define()` — the manifest
     * definitions participate in the enable transaction and are visible before
     * its after-commit notification. The bound store must use this connection.
     * `define()` is register-once and preserves administrator overrides.
     *
     * @internal spec: P1.25, A68, P1.22
     */
    private function defineManifestFeatureFlags(string $module): void
    {
        DB::usingConnection($this->connection ?? DB::getDefaultConnection(), function () use ($module): void {
            /** @var FeatureFlags $flags */
            $flags = $this->container->make(FeatureFlags::class);

            foreach ($this->manifestFor($module)->featureFlags as $definition) {
                $flags->define($definition);
            }
        });
    }

    public function disable(string $module, DisableReason $reason, bool $cascade = false): void
    {
        $tenant = $this->tenantContextResolver->current();

        $this->db()->transaction(function () use ($module, $reason, $cascade, $tenant): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null || $current->state !== ModuleState::Enabled->value) {
                return; // nothing enabled to disable — no-op.
            }

            $dependents = $this->enabledDependents($module);

            if ($dependents !== [] && ! $cascade) {
                throw new HasEnabledDependentsException($module, $dependents);
            }

            foreach ($dependents as $dependent) {
                $this->disable($dependent, DisableReason::Dependency, cascade: true);
            }

            $context = new ModuleContext($module, $tenant, $this->records);
            $this->invokeHook($this->manifestFor($module)->onDisableHook, $context);

            $this->db()->table(self::MODULES_TABLE)->where('name', $module)->update([
                'state' => ModuleState::Disabled->value,
                'disable_reason' => $reason->value,
                'disabled_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);

            $this->log($module, 'disable', ModuleState::Enabled->value, ModuleState::Disabled->value, 'success', [
                'reason' => $reason->value,
            ]);

            $this->db()->afterCommit(static fn () => event(new ModuleDisabled($module, $reason)));
        });
    }

    public function uninstall(string $module, bool $keepData = true): void
    {
        $tenant = $this->tenantContextResolver->current();

        $this->db()->transaction(function () use ($module, $keepData, $tenant): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null) {
                return; // nothing to uninstall.
            }

            if (! in_array($current->state, [ModuleState::Disabled->value, ModuleState::Archived->value], true)) {
                throw new LogicException(sprintf(
                    'Module [%s] must be disabled before uninstall (current state: %s) — B-10 §5.1 two-step safety.',
                    $module,
                    $current->state,
                ));
            }

            if ($keepData) {
                $this->db()->table(self::MODULES_TABLE)->where('name', $module)->update([
                    'state' => ModuleState::Archived->value,
                    'updated_at' => $this->timestamp(),
                ]);

                $this->log($module, 'archive', $current->state, ModuleState::Archived->value, 'success', []);

                $this->db()->afterCommit(static fn () => event(new ModuleArchived($module)));

                return;
            }

            $this->purge($module, $current->state, $tenant);
        });
    }

    public function upgrade(string $module): void
    {
        $this->db()->transaction(function () use ($module): void {
            $this->lock($module);

            $current = $this->currentRow($module);

            if ($current === null) {
                throw new ModuleNotFound($module);
            }

            // FSM gate: catch-up migrations only make sense for a live module.
            if ($current->state !== ModuleState::Enabled->value) {
                throw InvalidStateTransitionException::upgradeRequiresEnabled($module, $current->state);
            }

            $migration = $this->runModuleMigrations($module);
            $codeVersion = $this->codeVersion($module);

            // All-or-nothing, symmetric to enable(): a failing
            // catch-up migration aborts the WHOLE transition — migrations 1..K,
            // the version/schema_version bump and the log row all roll back — and
            // surfaces to the caller. The older code committed
            // `schema_version=end(ran)` and logged 'failed' WITHOUT rethrowing,
            // leaving the module silently half-migrated and the caller none the
            // wiser (the symmetric silent-failure this closes).
            if ($migration['error'] instanceof Throwable) {
                throw new ModuleUpgradeFailedException($module, $migration['error']);
            }

            $fromVersion = $current->version;

            $this->db()->table(self::MODULES_TABLE)->where('name', $module)->update([
                'version' => $codeVersion,
                'schema_version' => $migration['ran'] !== [] ? end($migration['ran']) : $current->schema_version,
                'updated_at' => $this->timestamp(),
            ]);

            $this->log($module, 'upgrade', $current->state, $current->state, 'success', [
                'migrations_run' => $migration['ran'],
                'from_version' => $fromVersion,
                'to_version' => $codeVersion,
            ]);

            $this->db()->afterCommit(static fn () => event(new ModuleUpgraded($module, $fromVersion, $codeVersion, $migration['ran'])));
        });
    }

    public function repair(string $module): void
    {
        $this->db()->transaction(function () use ($module): void {
            $this->lock($module);

            // Reconcile the ownership registry with the real schema: drop claims
            // whose table/column/index vanished out of band.
            $this->reconcileClaims($module);

            $current = $this->currentRow($module);

            if ($current === null || $current->state !== ModuleState::Purged->value) {
                return; // a healthy (or already-available) module keeps its row.
            }

            // Legal exit from the `purged` dead end: reset the module to
            // `available` so a fresh enable() re-runs its migrations from
            // scratch ("purged → enabled: как первый"). Forgetting the
            // migration history is what makes that re-run actually apply the DDL
            // again instead of skipping it as already-ran.
            $this->forgetModuleMigrations($module);
            $this->db()->table(self::RECORDS_TABLE)->where('module_name', $module)->delete();
            $this->db()->table(self::MODULES_TABLE)->where('name', $module)->delete();
        });
    }

    public function status(string $module): ModuleStatus
    {
        $current = $this->currentRow($module);
        $codeVersion = $this->codeVersion($module);

        if ($current === null) {
            return new ModuleStatus($module, ModuleState::Available, $codeVersion, null, false, 'standard');
        }

        return new ModuleStatus(
            name: $module,
            state: ModuleState::from($current->state),
            codeVersion: $codeVersion,
            dbVersion: $current->version,
            pendingUpgrade: version_compare($codeVersion, $current->version, '>'),
            edition: $current->edition,
        );
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function lock(string $module): void
    {
        $this->db()->statement('select pg_advisory_xact_lock(hashtext(?))', ['corex:module-lifecycle-graph']);
        $this->db()->statement('select pg_advisory_xact_lock(hashtext(?))', [$module]);
    }

    private function currentRow(string $module): ?object
    {
        return $this->db()->table(self::MODULES_TABLE)->where('name', $module)->first();
    }

    private function ensureDependencyEnabled(string $module, string $target, bool $cascade): void
    {
        $targetState = $this->currentRow($target)?->state;

        if ($targetState === ModuleState::Enabled->value) {
            return;
        }

        if (! $cascade) {
            throw new MissingDependencyException($module, $target);
        }

        $this->enable($target, cascade: true);
    }

    /** @return list<string> */
    private function enabledDependents(string $module): array
    {
        $dependents = [];

        foreach ($this->discovery->discover() as $manifest) {
            foreach ($manifest->requires as $dep) {
                if ($dep->type !== 'module' || $dep->target !== $module) {
                    continue;
                }

                if ($this->currentRow($manifest->composerName)?->state === ModuleState::Enabled->value) {
                    $dependents[] = $manifest->composerName;
                }

                break;
            }
        }

        return $dependents;
    }

    private function purge(string $module, string $fromState, TenantContext $tenant): void
    {
        // Defensive FSM guard: a purged module cannot leave live dependents
        // behind (disable already blocks that, but purge asserts it too).
        $dependents = $this->enabledDependents($module);

        if ($dependents !== []) {
            throw new HasEnabledDependentsException($module, $dependents);
        }

        $context = new ModuleContext($module, $tenant, $this->records);
        $this->invokeHook($this->manifestFor($module)->onPurgeHook, $context);

        // Tear every owned object down in REVERSE creation order (`seq`,
        // not the clock), across ALL claim types, so FK children drop
        // before parents and no schema object (table/column/index) is orphaned.
        $claims = $this->db()->table(self::RECORDS_TABLE)
            ->where('module_name', $module)
            ->orderByDesc('seq')
            ->get(['record_type', 'reference']);

        $droppedTables = [];

        foreach ($claims as $claim) {
            match ($claim->record_type) {
                'table' => $this->dropOwnedTable((string) $claim->reference, $droppedTables),
                'column' => $this->dropOwnedColumn((string) $claim->reference),
                'index' => $this->dropOwnedIndex((string) $claim->reference),
                // role/permission/menu/setting/seed/blueprint/feature_flag/other
                // are logical claims with no core-owned schema sink in P1 (roles
                // are aut_* in P2, menus land later): their teardown is the
                // PurgeHook above + the ownership-row delete below. They carry no
                // information_schema footprint, so the "0 orphans" AC still holds.
                default => null,
            };
        }

        $this->db()->table(self::RECORDS_TABLE)->where('module_name', $module)->delete();

        $this->db()->table(self::MODULES_TABLE)->where('name', $module)->update([
            'state' => ModuleState::Purged->value,
            'updated_at' => $this->timestamp(),
        ]);

        $this->log($module, 'purge', $fromState, ModuleState::Purged->value, 'success', [
            'dropped_tables' => $droppedTables,
        ]);

        $this->db()->afterCommit(static fn () => event(new ModulePurged($module, $droppedTables)));
    }

    /** @param  list<string>  $droppedTables */
    private function dropOwnedTable(string $table, array &$droppedTables): void
    {
        Schema::connection($this->connection)->dropIfExists($table);
        $droppedTables[] = $table;
    }

    private function dropOwnedColumn(string $reference): void
    {
        [$table, $column] = array_pad(explode('.', $reference, 2), 2, null);

        if ($column === null) {
            return;
        }

        $this->db()->statement(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', $this->quoteIdentifier($table), $this->quoteIdentifier($column)));
    }

    private function dropOwnedIndex(string $index): void
    {
        $this->db()->statement(sprintf('DROP INDEX IF EXISTS %s', $this->quoteIdentifier($index)));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function reconcileClaims(string $module): void
    {
        $objects = $this->schemaObjects();

        $claims = $this->db()->table(self::RECORDS_TABLE)
            ->where('module_name', $module)
            ->whereIn('record_type', ['table', 'column', 'index'])
            ->get(['record_type', 'reference']);

        foreach ($claims as $claim) {
            $reference = (string) $claim->reference;

            $exists = match ($claim->record_type) {
                'table' => isset($objects['tables'][$reference]),
                'column' => isset($objects['columns'][$reference]),
                'index' => isset($objects['indexes'][$reference]),
                default => true,
            };

            if ($exists) {
                continue;
            }

            $this->db()->table(self::RECORDS_TABLE)
                ->where('module_name', $module)
                ->where('record_type', $claim->record_type)
                ->where('reference', $reference)
                ->delete();
        }
    }

    private function forgetModuleMigrations(string $module): void
    {
        $path = $this->migrationPaths[$module] ?? null;

        if ($path === null) {
            return;
        }

        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob(rtrim($path, '/').'/*.php') ?: [],
        );

        if ($names !== []) {
            $this->db()->table(self::MIGRATIONS_TABLE)->whereIn('migration', $names)->delete();
        }
    }

    private function invokeHook(?string $hookClass, ModuleContext $context): void
    {
        if ($hookClass === null) {
            return;
        }

        /** @var callable(ModuleContext): void $hook */
        $hook = $this->container->make($hookClass);
        $hook($context);
    }

    private function manifestFor(string $module): Manifest
    {
        foreach ($this->discovery->discover() as $manifest) {
            if ($manifest->composerName === $module) {
                return $manifest;
            }
        }

        throw new ModuleNotFound($module);
    }

    /**
     * Runs every pending migration under the module's registered migrations
     * path via Laravel's own migrator/repository, wrapping each migration in a
     * schema-diff that auto-claims the tables/columns/indexes it creates
     * ({@see withMigrationClaims()}) — no hand-written claim() in migrations.
     *
     * @return array{ran: list<string>, error: ?Throwable}
     */
    private function runModuleMigrations(string $module): array
    {
        $path = $this->migrationPaths[$module] ?? null;

        if ($path === null) {
            return ['ran' => [], 'error' => null];
        }

        /** @var Migrator $migrator */
        $migrator = $this->container->make('migrator');

        return $migrator->usingConnection($this->connection, function () use ($migrator, $path, $module): array {
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }

            $before = $repository->getRan();

            $error = $this->withMigrationClaims($module, static function () use ($migrator, $path): void {
                $migrator->run([$path]);
            });

            $after = $repository->getRan();

            return ['ran' => array_values(array_diff($after, $before)), 'error' => $error];
        });
    }

    /**
     * Auto-claim wrapper: snapshots the schema around each `up()` and
     * attributes the new tables/columns/indexes to $module. Claims are written
     * in creation order (tables by pg_class.oid), so the bigserial `seq` on
     * `mod_records` encodes creation order and purge is FK-safe. The listeners
     * are removed by identity in `finally`, preserving application listeners,
     * including listeners registered during a migration.
     *
     * @internal spec: D41
     */
    private function withMigrationClaims(string $module, Closure $run): ?Throwable
    {
        /** @var Dispatcher $events */
        $events = $this->container->make('events');

        /** @var array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}|null $snapshot */
        $snapshot = null;

        $started = function (MigrationStarted $event) use (&$snapshot): void {
            if ($event->method === 'up') {
                $snapshot = $this->schemaObjects();
            }
        };

        $ended = function (MigrationEnded $event) use (&$snapshot, $module): void {
            if ($event->method !== 'up' || $snapshot === null) {
                return;
            }

            $this->claimSchemaDelta($module, $snapshot, $this->schemaObjects());
            $snapshot = null;
        };

        $events->listen(MigrationStarted::class, $started);
        $events->listen(MigrationEnded::class, $ended);

        try {
            $run();

            return null;
        } catch (Throwable $error) {
            return $error;
        } finally {
            foreach ([MigrationStarted::class => $started, MigrationEnded::class => $ended] as $event => $owned) {
                $listeners = $events->getRawListeners()[$event] ?? [];
                $events->forget($event);
                foreach ($listeners as $listener) {
                    if ($listener !== $owned) {
                        $events->listen($event, $listener);
                    }
                }
            }
        }
    }

    /**
     * @param  array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}  $before
     * @param  array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}  $after
     */
    private function claimSchemaDelta(string $module, array $before, array $after): void
    {
        // New tables first, in creation (oid) order — a child table created
        // after its parent gets the higher seq and therefore drops first.
        $newTables = array_diff_key($after['tables'], $before['tables']);
        asort($newTables);

        foreach (array_keys($newTables) as $table) {
            $this->records->claim($module, 'table', $table);
        }

        // Columns added to a table that ALREADY existed (cross-module extension
        // — a module adds a column to another module's table). Columns of
        // a brand-new table need no separate claim: dropping the table drops
        // them.
        foreach (array_keys($after['columns']) as $key) {
            if (isset($before['columns'][$key])) {
                continue;
            }

            $table = explode('.', $key, 2)[0];

            if (isset($before['tables'][$table])) {
                $this->records->claim($module, 'column', $key);
            }
        }

        // Indexes created on a pre-existing table (same reasoning as columns).
        foreach ($after['indexes'] as $index => $table) {
            if (isset($before['indexes'][$index])) {
                continue;
            }

            if (isset($before['tables'][$table])) {
                $this->records->claim($module, 'index', $index);
            }
        }
    }

    /**
     * Current-schema tables (name → oid), columns ("table.column" → true) and
     * indexes (name → table) on the module connection. oid is a monotonic
     * creation-order proxy (pg_class); index/column membership lets the diff
     * tell a brand-new table apart from an extension of an existing one.
     *
     * @return array{tables: array<string, int>, columns: array<string, bool>, indexes: array<string, string>}
     */
    private function schemaObjects(): array
    {
        $connection = $this->db();

        $tables = [];

        foreach ($connection->select("select c.relname as name, c.oid::bigint as oid from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind = 'r' and n.nspname = current_schema()") as $row) {
            $tables[(string) $row->name] = (int) $row->oid;
        }

        $columns = [];

        foreach ($connection->select('select table_name, column_name from information_schema.columns where table_schema = current_schema()') as $row) {
            $columns[$row->table_name.'.'.$row->column_name] = true;
        }

        $indexes = [];

        foreach ($connection->select("select i.relname as name, t.relname as tbl from pg_class i join pg_index ix on ix.indexrelid = i.oid join pg_class t on t.oid = ix.indrelid join pg_namespace n on n.oid = i.relnamespace where i.relkind = 'i' and n.nspname = current_schema()") as $row) {
            $indexes[(string) $row->name] = (string) $row->tbl;
        }

        return ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];
    }

    private function codeVersion(string $module): string
    {
        return $this->codeVersions[$module] ?? '0.0.0';
    }

    /** @param  list<string>  $migrationsRun */
    private function upsertModuleRow(string $module, ?object $current, ModuleState $state, string $edition, array $migrationsRun): void
    {
        $now = $this->timestamp();
        $schemaVersion = $migrationsRun !== [] ? end($migrationsRun) : ($current->schema_version ?? null);

        if ($current !== null) {
            $this->db()->table(self::MODULES_TABLE)->where('name', $module)->update([
                'state' => $state->value,
                'version' => $this->codeVersion($module),
                'schema_version' => $schemaVersion,
                'edition' => $edition,
                'enabled_at' => $now,
                'disabled_at' => null,
                'disable_reason' => null,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->db()->table(self::MODULES_TABLE)->insert([
            'id' => (string) Str::uuid7(),
            'name' => $module,
            'state' => $state->value,
            'version' => $this->codeVersion($module),
            'schema_version' => $schemaVersion,
            'edition' => $edition,
            'enabled_at' => $now,
            'disabled_at' => null,
            'disable_reason' => null,
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'meta' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Append a lifecycle-log row. Beyond the audit trail, this insert
     * is the registry EPOCH bump: every terminal transition
     * (enable/disable/upgrade/archive/purge) calls log() INSIDE this
     * transaction, so the append-only row count in `mod_lifecycle_log` is a
     * monotonic per-account registry version bumped atomically with the state
     * change. The hot-path cache keys off that count
     * ({@see ModulesServiceProvider::registryVersion()}), so a
     * transition here invalidates every process's/node's cached enabled-set
     * without any forget(). Removing a log() call therefore un-bumps the epoch
     * and leaves other processes serving a stale enabled-set.
     *
     * Actor is sourced from {@see CurrentActor}. Currently that only
     * distinguishes an authenticated user from `system` — both valid under the
     * current CHECK; agent/api_key actors arrive with entity-scoped auth later.
     *
     * @param  array<string, mixed>  $details
     *
     * @internal spec: AC-13, D39, B-10 §3.1, P1, P2
     */
    private function log(string $module, string $operation, string $from, string $to, string $status, array $details): void
    {
        $this->db()->table(self::LOG_TABLE)->insert([
            'id' => (string) Str::uuid7(),
            'module_name' => $module,
            'operation' => $operation,
            'from_state' => $from,
            'to_state' => $to,
            'status' => $status,
            'actor_type' => CurrentActor::type()->value,
            'actor_id' => CurrentActor::id(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ]);
    }
}
