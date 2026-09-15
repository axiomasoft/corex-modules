<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use CoreX\Modules\Contracts\ModuleLifecycle;
use RuntimeException;
use Throwable;

/**
 * A catch-up migration failed during {@see ModuleLifecycle::upgrade()}.
 *
 * upgrade() is all-or-nothing, symmetric to enable(): a failing catch-up
 * migration rolls the WHOLE transition back — migrations 1..K, the
 * `mod_modules` version/schema_version bump and the log row all roll back — and
 * rethrows this so the caller (a control-plane job) learns of it instead of a
 * module left silently half-migrated with schema_version advanced past the last
 * table that actually applied. A retry after the fix starts from the real
 * on-disk schema.
 *
 * @internal spec: D40, P1.29 M-1
 */
final class ModuleUpgradeFailedException extends RuntimeException
{
    public function __construct(string $module, Throwable $previous)
    {
        parent::__construct(
            sprintf('Upgrading module [%s] failed and was rolled back: %s', $module, $previous->getMessage()),
            previous: $previous,
        );
    }
}
