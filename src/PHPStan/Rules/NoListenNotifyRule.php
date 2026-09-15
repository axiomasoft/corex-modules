<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

/**
 * LISTEN/NOTIFY — сессионный канал; через пулер подписка уезжает на чужой
 * бекенд. Легальный путь доставки — контракт `CoreX\Contracts\PubSub`.
 *
 * Запрет тотален СОЗНАТЕЛЬНО: PgBouncer фактически проносит NOTIFY (не LISTEN),
 * но код модуля не должен зависеть от версии и настроек пулера.
 *
 * @internal spec: B-10 §7.3 п.4
 */
final class NoListenNotifyRule extends AbstractDatabaseCallRule
{
    protected function isViolation(string $sql): bool
    {
        if (preg_match('/\bpg_notify\s*\(/i', $sql) === 1) {
            return true;
        }

        foreach ($this->statements($sql) as $statement) {
            if (preg_match('/^(un)?listen\b|^notify\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function message(): string
    {
        return 'LISTEN/NOTIFY is forbidden under transaction pooling (B-10 §7.3 п.4) — publish through the PubSub contract.';
    }

    protected function identifier(): string
    {
        return 'corexModules.listenNotify';
    }
}
