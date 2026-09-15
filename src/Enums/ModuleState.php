<?php

declare(strict_types=1);

namespace CoreX\Modules\Enums;

/**
 * Per-account module state machine. `Available` is virtual — code is
 * deployed but `mod_modules` has no row for the module yet.
 *
 * @internal spec: B-10 §5.1
 */
enum ModuleState: string
{
    case Available = 'available';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Archived = 'archived';
    case Purged = 'purged';
}
