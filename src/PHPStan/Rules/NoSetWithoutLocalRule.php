<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

/**
 * `SET` держит состояние в сессии, которую transaction pooling отдаёт
 * другому клиенту после коммита. Легален только `SET LOCAL` в транзакции.
 *
 * @internal spec: B-10 §7.3 п.1
 */
final class NoSetWithoutLocalRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        foreach ($this->statements($sql) as $statement) {
            if (preg_match('/^set\s+(?!local\b)/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function message(): string
    {
        return 'Session-level SET is forbidden under transaction pooling (B-10 §7.3 п.1) — use SET LOCAL inside a transaction.';
    }

    protected function identifier(): string
    {
        return 'corexModules.setWithoutLocal';
    }
}
