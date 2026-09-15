<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

/**
 * Временная таблица живёт в сессии, а не в транзакции — после коммита пул
 * отдаёт бекенд другому клиенту, и таблица «исчезает» либо течёт.
 *
 * @internal spec: B-10 §7.3 п.2
 */
final class NoTempTableRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        return preg_match('/\bcreate\s+(global\s+|local\s+)?(temp|temporary)\s+table\b/i', $sql) === 1;
    }

    protected function message(): string
    {
        return 'Temporary tables are forbidden under transaction pooling (B-10 §7.3 п.2).';
    }

    protected function identifier(): string
    {
        return 'corexModules.tempTable';
    }
}
