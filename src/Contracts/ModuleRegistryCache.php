<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use Closure;

/**
 * Cache seam for {@see ModuleRegistry}'s per-account enabled-set.
 *
 * The implementation MUST NOT call `Repository::tags()`: file/database stores
 * are not taggable, so tagging throws `BadMethodCallException` on the default
 * Laravel cache and in the boxed profile. The account id already namespaces
 * the key, so tags bought nothing anyway.
 *
 * Invalidation is by EPOCH, not by `forget()`: the caller stamps a monotonic
 * per-account `$version` into the key. A bumped version yields a fresh key,
 * so a stale entry is never read and nothing has to be cleared — which is
 * exactly why the fix survives a multi-node file cache and a long-running
 * `queue:work`, where a caller-local `forget()` would not.
 *
 * @internal spec: B-10 §6.1
 */
interface ModuleRegistryCache
{
    /**
     * @param  Closure(): array<string, array{state: string, edition: string}>  $resolver
     * @return array<string, array{state: string, edition: string}>
     */
    public function rememberEnabled(string $accountId, int $version, Closure $resolver): array;
}
