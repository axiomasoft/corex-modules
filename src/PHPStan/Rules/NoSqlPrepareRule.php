<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

/**
 * SQL-level `PREPARE`/`DEALLOCATE` привязывает стейтмент к серверному
 * коннекту, которого у клиента после коммита уже нет.
 *
 * Запрет тотален СОЗНАТЕЛЬНО: protocol-level prepared statements PgBouncer ≥1.21
 * проносит (`max_prepared_statements`), но это свойство инфры, а не кода модуля.
 *
 * @internal spec: B-10 §7.3 п.5
 */
final class NoSqlPrepareRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        foreach ($this->statements($sql) as $statement) {
            if (preg_match('/^(prepare|deallocate)\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function message(): string
    {
        return 'SQL-level PREPARE/DEALLOCATE is forbidden under transaction pooling (B-10 §7.3 п.5).';
    }

    protected function identifier(): string
    {
        return 'corexModules.sqlPrepare';
    }
}
