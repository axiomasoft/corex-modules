# Changelog

Все заметные изменения `corex/modules` документируются здесь. Формат — [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Пакет pre-1.0 — breaking-изменения публичных контрактов легальны в MINOR до 1.0 (D17).

## [Unreleased]

### Added

- `RegistryIntrospection` contract with `RegistryMap` / `ModuleExplanation` DTOs, read-only
  `ReadRegistryIntrospection` implementation and `corex:modules:why` operator CLI for bounded
  extension-point and activation diagnostics.

### Fixed

- Lifecycle checks the activation gate before migrations/hooks, serializes the
  dependency graph, preserves external migration listeners, and defines flags
  transactionally before the enable notification. Public contracts are unchanged.

### API operations

- Backward-compatible `Api::operation()` declarations with explicit handler, ability, version, schemas and validation rules. Compilation stamps module ownership and rejects duplicate IDs/routes and malformed declarations. Existing resource metadata never becomes implicit CRUD.
- Explicit tool declarations: `Api::tool()` and `Api::exposeTool()` compile into the existing API slice with canonical tuple/wire validation, contract hashing and duplicate rejection via `ApiToolValidator`.

### Added

- **`mod_lifecycle_log.actor_ck` widened to include `support` (P2.11, D138).** A second CHECK
  found during P2.11 design: `DatabaseModuleLifecycle` writes `CurrentActor::type()->value` into
  this table, whose vocabulary is independent of `sys_audit_log`'s — without this additive
  migration, the first lifecycle operation performed under impersonation would fail with a
  CHECK-violation. The closed P1.3 migration file is not edited.

### Changed

- Шапка `README.md` продукт-нейтральна («Модульная система Flex» → «Модульная система CoreX») —
  уровень 0 доктрины (D91) не несёт привязки к продукту-потребителю (P2.17).
- Продуктовые докблоки публичной поверхности `Contracts/**`, `Extend/**`, `CorexModule.php`,
  `Registry/FieldDefinition.php`: убраны внутренние спека-ссылки (B-10 §…) из основного текста,
  перенесены в трейлинг `@internal spec: …`; терминология «Flex module» → «CoreX module» (P3.6).
- **Breaking:** пакет и namespace переименованы `flex/modules`/`Flex\Modules\` →
  `corex/modules`/`CoreX\Modules\` (доктрина слоёв, D70/D72). Consumer-код обновляет `use`/`require`
  на новые имена (P2.0).
- **Breaking:** де-flex публичной поверхности (аудит A5/A10, P3.3) — конфиг-файл/ключ, env-префикс,
  artisan-команды, publish-теги, путь компилята кеша и PHPStan-неймспейс/identifiers, ранее нёсшие
  legacy Flex-именование, переведены на `corex-modules`/`COREX_MODULES_*`/`corex:modules:*`-эквиваленты
  (см. `README.md`/`config/corex-modules.php`). Consumer-код обновляет `.env`/публикованный
  конфиг/CI-раннеры на новые имена.

## [Unreleased]

### Added

- `Enums\Capability`: two new cases `Ownable`/`WorkspaceScoped` (`corex/auth` P2.9 —
  admit RBAC scopes `own`/`dept`/`dept_tree` and `workspace` respectively); the
  existing 7 cases keep their values and ordinal position.

## [0.1.0] - 2026-07-15

Первый релиз. Реализует roadmap-фазу P0 / блупринт B-10 (модульная система).

### Requires

- **PostgreSQL 16** для тенантской БД. Таблицы `mod_*` и lifecycle-FSM используют
  `pg_advisory_xact_lock`, `CHECK`-констрейнты и `jsonb` — без SQLite-эквивалента. `composer test`
  (SQLite) покрывает только pure-PHP код; гарантии lifecycle/purge-порядка/кеш-инвалидации
  проверяются исключительно на `composer test:pg`.

### Added

- `EntityRegistry` v2 (`EntityDefinition`/`FieldDefinition`/`Capability`, 7 значений capability),
  мигрирован из `corex/core`; handle-based морф-карта (P1.2).
- Манифест модуля (`CorexModule`/`Manifest`/`Dep`) и discovery через сканирование `installed.json`,
  команда `flex:modules:compile` (P1.3); compile-time валидатор `tablePrefix`, `requires`↔composer
  парити, коллизий handle (P1.17).
- `RegistryCompiler` с закрытым пайплайном 8 extender'ов (`Entity → EntityField → Permissions →
  Api → Filament → Menu → Routes → Workflow`), детерминированный порядок, «спящие» межмодульные
  extender'ы (P1.4).
- `ModuleRegistry` compiled hot-path (`bootstrap/cache/flex_modules.php`, атомарная запись),
  `ModuleActivationGate` (боксед-дефолт `AllowAll`) (P1.5); персистентная проекция `CompiledRegistry`
  и безопасный контракт кеш-тегов с инвалидацией (P1.15, P1.16).
- `ModuleLifecycle` FSM (`enable`/`disable`/`uninstall`/`upgrade`/`purge`), `RecordsRegistrar`
  (claim/release владения), таблицы `mod_modules`/`mod_records`/`mod_lifecycle_log`,
  `pg_advisory_xact_lock`-сериализация переходов, монотонный FK-safe порядок purge, явный `actor`
  в логе lifecycle, не-молчащий провал `enable()` (P1.6, P1.18).
- Lifecycle-события перенесены на `DomainEvent` (из `corex/core`) + хвосты wiring
  `SettingDefaults`/`FeatureFlags` (P1.25).
- Новый PG-лейн тестов (`composer test:pg`, docker-compose PostgreSQL 16) и двухлейновый CI-гейт
  (`composer lint:check && composer analyse && composer test && composer test:pg`) (P1.14).
- Кастомный набор PHPStan-правил `flex/modules-lint` (9 правил, дисциплина transaction-pooling
  в raw-SQL lifecycle-коде) (P1.13).
- Новое доменное исключение `ModuleUpgradeFailedException` (P1.29).

### Fixed

- `DatabaseModuleLifecycle::upgrade()`: провал catch-up-миграции теперь откатывается и **бросается
  наружу** (`ModuleUpgradeFailedException`) вместо тихого бампа `schema_version` и лога `failed` —
  симметрично all-or-nothing поведению `enable()` (M-1, P1.29).

### Known limitations

- Per-call резолвинг `ctx → connection` для реестра/lifecycle модулей отложен в P2 tenancy (D13);
  текущий шов — статический `?string $connection`.
- `composer test:coverage --min` временно понижен до измеренного пола (55%) до обкатки ядра на
  реальных проектах и восстановления канонических 80% (P1.27, time-gated).
- Второй прогон PHPStan (`flex/modules-lint` правил на `tests/`) выделен отдельным item'ом (P1.28).
