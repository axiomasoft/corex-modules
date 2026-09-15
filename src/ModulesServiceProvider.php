<?php

declare(strict_types=1);

namespace CoreX\Modules;

use CoreX\Contracts\SettingDefaultsProvider;
use CoreX\CoreServiceProvider;
use CoreX\Modules\Audit\AuditCapabilityWiring;
use CoreX\Modules\Commands\CompileModulesCommand;
use CoreX\Modules\Contracts\EntityRegistry;
use CoreX\Modules\Contracts\ModuleActivationGate;
use CoreX\Modules\Contracts\ModuleLifecycle;
use CoreX\Modules\Contracts\ModuleRegistry;
use CoreX\Modules\Contracts\ModuleRegistryCache;
use CoreX\Modules\Contracts\ModuleStateReader;
use CoreX\Modules\Contracts\RecordsRegistrar;
use CoreX\Modules\Discovery\ModuleDiscovery;
use CoreX\Modules\Gate\AllowAllActivationGate;
use CoreX\Modules\Lifecycle\DatabaseModuleLifecycle;
use CoreX\Modules\Lifecycle\DatabaseModuleStateReader;
use CoreX\Modules\Lifecycle\DatabaseRecordsRegistrar;
use CoreX\Modules\Registry\ArrayEntityRegistry;
use CoreX\Modules\Registry\CompiledModuleRegistry;
use CoreX\Modules\Registry\RepositoryModuleRegistryCache;
use CoreX\Modules\Settings\CompiledSettingDefaultsProvider;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use CoreX\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Override;

final class ModulesServiceProvider extends ServiceProvider
{
    /**
     * Shipped schema generation of the mod_* migration set. A consumer
     * publishes a COPY, so upgrades ship as NEW additive migration files under
     * the same publish tag and bump this constant (mirrors
     * {@see CoreServiceProvider::SCHEMA_VERSION}).
     *
     * @internal spec: D43
     */
    public const int SCHEMA_VERSION = 1;

    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/corex-modules.php', 'corex-modules');

        // Bind the concrete singleton first so module providers can resolve
        // it directly to call register() (not part of the read-only contract),
        // then alias the contract to the same instance. The active-module
        // predicate is wired to the real ModuleRegistry::isActive
        // — resolved lazily at call time so all(null) at boot never triggers it
        // (and never a DB read).
        $this->app->singleton(ArrayEntityRegistry::class, static function (Application $app): ArrayEntityRegistry {
            return new ArrayEntityRegistry(
                moduleActive: static fn (string $module, TenantContext $ctx): bool => $app->make(ModuleRegistry::class)->isActive($module, $ctx),
            );
        });
        $this->app->singleton(
            EntityRegistry::class,
            static fn (Application $app): ArrayEntityRegistry => $app->make(ArrayEntityRegistry::class),
        );

        // No fallback modules by default — real deploys scan installed.json;
        // tests/testbench rebind this with an injected class list.
        $this->app->singleton(ModuleDiscovery::class);

        // Boxed default: the cloud billing gate is an application concern.
        $this->app->singleton(ModuleActivationGate::class, AllowAllActivationGate::class);

        // mod_modules/mod_records now exist — read/write them directly;
        // ArrayModuleStateReader remains available for tests that inject a fake.
        // Bound via a closure (NOT a bare class-string): the reader MUST read the
        // module connection (config('corex-modules.connection'), null = app
        // default) — the same seam RecordsRegistrar/lifecycle/epoch use. A bare
        // class-string autowires $connection = null and silently reads the ambient
        // default, so with a configured module DB the enabled set comes back empty
        // and every module reads inactive (a defect a targeted audit caught).
        $this->app->singleton(ModuleStateReader::class, static function (): DatabaseModuleStateReader {
            /** @var string|null $connection */
            $connection = config('corex-modules.connection');

            return new DatabaseModuleStateReader($connection);
        });

        // RecordsRegistrar and the lifecycle read/write the module connection
        // (config('corex-modules.connection'), null = app default) — the same
        // seam the epoch and state reader use, so auto-claims land in the module
        // DB, not the ambient default.
        $this->app->singleton(RecordsRegistrar::class, static function (): DatabaseRecordsRegistrar {
            /** @var string|null $connection */
            $connection = config('corex-modules.connection');

            return new DatabaseRecordsRegistrar($connection);
        });

        // codeVersions / migrationPaths (composer name → value) come from
        // installed.json (empty autowired arrays = migrations never run
        // in prod). Exposed as an overridable binding so tests inject stub paths
        // and resolve the lifecycle from the container, not a hand-built ctor.
        $this->app->singleton('corex-modules.wiring', fn (): array => $this->moduleWiring());

        $this->app->singleton(ModuleLifecycle::class, function (Application $app): DatabaseModuleLifecycle {
            /** @var array{codeVersions: array<string, string>, migrationPaths: array<string, string>} $wiring */
            $wiring = $app->make('corex-modules.wiring');
            /** @var string|null $connection */
            $connection = config('corex-modules.connection');

            return new DatabaseModuleLifecycle(
                registry: $app->make(ModuleRegistry::class),
                discovery: $app->make(ModuleDiscovery::class),
                records: $app->make(RecordsRegistrar::class),
                container: $app,
                tenantContextResolver: $app->make(TenantContextResolver::class),
                codeVersions: $wiring['codeVersions'],
                migrationPaths: $wiring['migrationPaths'],
                connection: $connection,
            );
        });

        // Registry cache WITHOUT tags: store/ttl/prefix from config
        // (no hardcoded 3600). store = null ⇒ the app's default store.
        $this->app->singleton(ModuleRegistryCache::class, static function (Application $app): RepositoryModuleRegistryCache {
            /** @var string|null $store */
            $store = config('corex-modules.cache.store');

            return new RepositoryModuleRegistryCache(
                store: $app->make(CacheFactory::class)->store($store),
                ttl: (int) config('corex-modules.cache.ttl', 3600),
                prefix: (string) config('corex-modules.cache.prefix', 'corex-modules'),
            );
        });

        $this->app->singleton(ModuleRegistry::class, function (Application $app): CompiledModuleRegistry {
            return new CompiledModuleRegistry(
                cachePath: CompiledModuleRegistry::defaultCachePath(),
                stateReader: $app->make(ModuleStateReader::class),
                cache: $app->make(ModuleRegistryCache::class),
                // Epoch: monotonic per-account registry version read from
                // the module connection. ctx is accepted for future per-account
                // resolution; today it reads the (single) module DB.
                versionResolver: fn (TenantContext $ctx): int => $this->registryVersion(),
                // Production must have compiled the registry at deploy; a
                // missing file there is a hard failure, not a silent empty
                // runtime (a defect class caught in an earlier pre-mortem).
                failIfMissing: $app->environment('production'),
            );
        });

        // Rebind the boxed Null default (CoreServiceProvider::register(),
        // registered before this provider) to the real manifest-backed
        // implementation — corex/modules is present, so
        // manifest-declared setting defaults now reach the runtime cascade.
        $this->app->singleton(
            SettingDefaultsProvider::class,
            static fn (Application $app): CompiledSettingDefaultsProvider => new CompiledSettingDefaultsProvider($app->make(ModuleRegistry::class)),
        );
    }

    public function boot(): void
    {
        // NOT loadMigrationsFrom: mod_* is PG-only DDL (CHECK
        // constraints, advisory locks) and this provider is registered by
        // every consumer, including sqlite-backed suites (Crm/Tasks). The
        // pg-lane test/deploy path runs these migrations explicitly,
        // scoped by --path (see tests/Feature/ModuleLifecycleTest.php).
        if ($this->app->runningInConsole()) {
            $this->commands([CompileModulesCommand::class]);

            // Reconcile a module's ownership registry with the real schema and
            // provide the single legal exit from the terminal `purged` state
            // — a thin wrapper over ModuleLifecycle::repair().
            Artisan::command('corex:modules:repair {module : Composer name of the module to repair}', function (ModuleLifecycle $lifecycle, string $module): int {
                $lifecycle->repair($module);
                $this->info(sprintf('Reconciled module [%s] with the schema.', $module));

                return 0;
            })->purpose('Reconcile mod_records with the schema and reset a purged module to available.');
        }

        // Connection-scoped publish: mod_* is PG-only TENANT-schema
        // DDL (advisory locks, CHECK, jsonb). NOT loadMigrationsFrom — see the
        // note above; consumers publish + migrate explicitly on the tenant
        // connection. The `-tenant` tag names that scope (caught in an earlier
        // pre-mortem); the plain `corex-modules-migrations` alias serves the
        // boxed single-DB case.
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['corex-modules-migrations', 'corex-modules-migrations-tenant']);

        $this->publishes([
            __DIR__.'/../config/corex-modules.php' => config_path('corex-modules.php'),
        ], 'corex-modules-config');

        // Epoch memo reset: a long-running queue worker drops its
        // per-process compiled-file memo before each job, so a redeploy's
        // recompiled registry is picked up instead of served stale for hours.
        $this->app['events']->listen(JobProcessing::class, function (): void {
            // Typed as the CONTRACT on purpose: a consumer may rebind
            // ModuleRegistry to an implementation without flush(), so the
            // instanceof stays a real guard (and larastan does not narrow the
            // container binding to the concrete, flagging it always-true).
            /** @var ModuleRegistry $registry */
            $registry = $this->app->make(ModuleRegistry::class);

            if ($registry instanceof CompiledModuleRegistry) {
                $registry->flush();
            }
        });

        // Deploy hook: `php artisan optimize` recompiles the registry. No
        // clear command — `optimize:clear` leaves the file, the next
        // `optimize` overwrites it atomically.
        $this->optimizes(optimize: 'corex:modules:compile', key: 'corex-modules');

        // Built after every module provider has booted and registered its
        // entities (ADR-003: EntityRegistry is the single morph-map source).
        $this->app->booted(function (): void {
            // The compiler creates the cache that this projection consumes.
            // Do not attempt to read it while its own command is booting on a
            // fresh production deploy.
            if ($this->isCompilingModules()) {
                return;
            }

            $arrayRegistry = $this->app->make(ArrayEntityRegistry::class);

            // Project the persisted compiled registry into the runtime
            // EntityRegistry so module-declared entities reach the app —
            // not only entities a provider register()'d imperatively.
            $compiled = $this->app->make(ModuleRegistry::class)->compiled();

            foreach ($compiled->entities as $definition) {
                $arrayRegistry->register($definition);
            }

            $registry = $this->app->make(EntityRegistry::class);
            $map = [];

            // Morph map spans the WHOLE registry (all() без ctx), never just
            // the active modules: reading a persisted row of a currently
            // disabled module must still resolve its morph alias, or
            // Relation::morphMap loses the handle. The active-module filter in
            // all(?ctx) is a DIFFERENT call — not this one.
            foreach ($registry->all() as $definition) {
                $map[$definition->handle] = $definition->model;
            }

            if ($map !== []) {
                Relation::morphMap($map);
            }

            // AuditObserver lives in corex/core (capability 'auditable') —
            // the wiring itself lives in corex/modules'
            // AuditCapabilityWiring because it needs EntityRegistry, and
            // corex/core cannot depend on corex/modules (reverse of this
            // package's own require).
            AuditCapabilityWiring::wire($registry);
        });
    }

    /**
     * Monotonic registry epoch: the count of applied lifecycle
     * transitions in `mod_lifecycle_log`. The log is append-only and each
     * terminal transition (enable/disable/upgrade/archive/purge) inserts a row
     * INSIDE the lifecycle transaction, so the count is a clock-free monotonic
     * version bumped atomically with the state change — and, living in the
     * shared DB, it is visible to every process/node (which a caller-local
     * cache clear would not be). The connection comes from
     * `config('corex-modules.connection')` (null = app default; multi-tenancy
     * rebinds that to the tenant DB). Missing table (pre-migrate / sqlite
     * suites) reads as epoch 0.
     *
     * @internal spec: D39
     */
    private function registryVersion(): int
    {
        /** @var string|null $connection */
        $connection = config('corex-modules.connection');

        if (! Schema::connection($connection)->hasTable('mod_lifecycle_log')) {
            return 0;
        }

        return (int) DB::connection($connection)->table('mod_lifecycle_log')->count();
    }

    private function isCompilingModules(): bool
    {
        return $this->app->runningInConsole()
            && ($_SERVER['argv'][1] ?? null) === 'corex:modules:compile';
    }

    /**
     * Build codeVersions / migrationPaths from `vendor/composer/installed.json`
     * (type: corex-module): version → codeVersions, install-path +
     * `/database/migrations` → migrationPaths. Empty when there is no
     * installed.json (testbench) or no module packages — real deploys populate
     * both so {@see DatabaseModuleLifecycle::runModuleMigrations()} actually
     * runs each module's migrations (empty autowired arrays ran nothing).
     *
     * @return array{codeVersions: array<string, string>, migrationPaths: array<string, string>}
     */
    private function moduleWiring(): array
    {
        $codeVersions = [];
        $migrationPaths = [];

        $path = base_path('vendor/composer/installed.json');

        if (! is_file($path)) {
            return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
        }

        /** @var mixed $data */
        $data = json_decode((string) file_get_contents($path), associative: true);

        if (! is_array($data)) {
            return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;
        $composerDir = dirname($path);

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['type'] ?? null) !== 'corex-module') {
                continue;
            }

            $name = $package['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            if (is_string($package['version'] ?? null)) {
                $codeVersions[$name] = $package['version'];
            }

            $installPath = $package['install-path'] ?? null;

            if (is_string($installPath)) {
                $migrationsDir = realpath($composerDir.'/'.$installPath.'/database/migrations');

                if ($migrationsDir !== false) {
                    $migrationPaths[$name] = $migrationsDir;
                }
            }
        }

        return ['codeVersions' => $codeVersions, 'migrationPaths' => $migrationPaths];
    }
}
