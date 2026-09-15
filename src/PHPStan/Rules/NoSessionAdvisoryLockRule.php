<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

/**
 * Session-level advisory lock переживает транзакцию и остаётся висеть на
 * чужом клиенте пула. Легальны только транзакционные `*_xact_*`.
 *
 * @internal spec: B-10 §7.3 п.3
 */
final class NoSessionAdvisoryLockRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        return preg_match('/\bpg_(try_)?advisory_(lock|unlock)(_shared|_all)?\s*\(/i', $sql) === 1;
    }

    protected function message(): string
    {
        return 'Session-level pg_advisory_lock/unlock is forbidden under transaction pooling (B-10 §7.3 п.3) — use pg_advisory_xact_lock.';
    }

    protected function identifier(): string
    {
        return 'corexModules.sessionAdvisoryLock';
    }
}
