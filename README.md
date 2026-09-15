# corex/modules

Модульная система CoreX (namespace `CoreX\Modules\`): манифест модуля, discovery,
`RegistryCompiler` + extenders, `ModuleRegistry` (compiled hot-path),
lifecycle-FSM (`enable`/`disable`/`upgrade`/`archive`/`purge`) и реестр владения
для безопасного purge. Ставится поверх `corex/core`. MIT.

## Требование к СУБД

Модульная система **требует PostgreSQL 16**: `mod_*`-таблицы и lifecycle-FSM
используют `pg_advisory_xact_lock`/`CHECK`/`jsonb`. SQLite-лейн покрывает
только pure-PHP код; lifecycle/purge/кеш-инвалидация проверяются
исключительно на `composer test:pg`.

## Установка миграций (важно)

Таблицы модульной системы (`mod_modules`, `mod_records`, `mod_lifecycle_log`) —
**PostgreSQL-only** (advisory-locks, `CHECK`, jsonb) и **тенантские**. Пакет их
**НЕ подключает автоматически** — схема ставится явной публикацией:

```bash
# 1. Скопировать миграции в приложение
php artisan vendor:publish --tag=corex-modules-migrations

# 2. Прогнать их НА ТЕНАНТСКОМ коннекшене
php artisan migrate --database=<tenant>
```

Тег `corex-modules-migrations-tenant` — синоним, явно называющий скоуп
коннекшена (`mod_*` — тенант-схема). Коннекшн, на котором работает hot-path
реестра и lifecycle, задаётся `config('corex-modules.connection')` (`null` =
дефолтный; под P2 stancl подменяет дефолт на базу тенанта).

### Версия схемы и апгрейды

Потребитель публикует **копию** миграций; изменения схемы поставляются **новыми
аддитивными миграциями** под тем же тегом (при апгрейде повторите
`vendor:publish` + `migrate`). Текущее поколение —
`CoreX\Modules\ModulesServiceProvider::SCHEMA_VERSION`.

## Идентификаторы и время

Ключевые колонки (`mod_modules.id`, `mod_lifecycle_log.id`/`actor_id`) следуют
`config('corex.ids.strategy')` через `CoreX\Support\Schema\IdColumns` (шов
corex/core). Все `timestamptz` — с явной микросекундной точностью
(`precision: 6`). Порядок purge в `mod_records` детерминирован монотонным
`seq bigserial` (не часами) — FK-безопасный обратный порядок удаления.

## Compatibility evidence

The factual compatibility passport, its local proof class, and explicit limits
are in [PACKAGE.md](PACKAGE.md). It does not extend Composer constraints or
constitute a publication promise.
