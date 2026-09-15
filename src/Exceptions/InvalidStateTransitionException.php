<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use LogicException;

/**
 * An FSM transition was requested that the module's current state forbids.
 * `purged` is terminal — re-installing a purged module goes through
 * `corex:modules:repair` (which returns it to `available`), never a direct
 * `enable` on the dead schema; `upgrade` requires `enabled`.
 *
 * @internal spec: B-10 §5.1 invariants, A11/A12
 */
final class InvalidStateTransitionException extends LogicException
{
    public static function purgedIsTerminal(string $module): self
    {
        return new self(sprintf(
            'Module [%s] is purged (terminal): its schema is gone. Run `corex:modules:repair %s` to reset it to available before enabling again — enabling directly would flip the row to enabled over dropped tables (A11).',
            $module,
            $module,
        ));
    }

    public static function upgradeRequiresEnabled(string $module, string $state): self
    {
        return new self(sprintf(
            'Module [%s] must be enabled to upgrade (current state: %s) — B-10 §5.5.',
            $module,
            $state,
        ));
    }
}
