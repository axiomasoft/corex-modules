<?php

declare(strict_types=1);

namespace CoreX\Modules\Enums;

/**
 * Why a module was disabled (`mod_modules.disable_reason`).
 *
 * @internal spec: B-10 §5.1/§5.4
 */
enum DisableReason: string
{
    case Manual = 'manual';
    case Billing = 'billing';
    case Dependency = 'dependency';
}
