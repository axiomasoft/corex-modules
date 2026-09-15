<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Module-system database connection
    |--------------------------------------------------------------------------
    | Connection the module hot-path (mod_modules / mod_records /
    | mod_lifecycle_log) and the registry epoch read from. `null` = the app's
    | default connection — correct for boxed / single-DB P1 installs, and
    | correct under P2 too (stancl rebinds the default connection to the
    | tenant's database per request). The DB-per-account map that lets a single
    | reader serve several accounts from one process arrives with corex/tenancy
    | (D13) — see phases/P1.md P1.16 Pending Work.
    */
    'connection' => env('COREX_MODULES_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Registry cache
    |--------------------------------------------------------------------------
    | The per-account enabled-set is cached WITHOUT cache tags (D30/A1/A2):
    | file/database stores are not taggable, so `tags()` would throw
    | BadMethodCallException on the default Laravel cache and in the boxed
    | profile (B-10 §6.1). Invalidation is by epoch (D39): a monotonic
    | registry version — the count of applied lifecycle transitions
    | (mod_lifecycle_log), bumped inside the lifecycle transaction — is stamped
    | into the cache key, so a stale key is simply never read.
    |
    | 'store' = null uses the app's default store.
    */
    'cache' => [
        'store' => env('COREX_MODULES_CACHE_STORE'),
        'ttl' => (int) env('COREX_MODULES_CACHE_TTL', 3600),
        'prefix' => (string) env('COREX_MODULES_CACHE_PREFIX', 'corex-modules'),
    ],

];
