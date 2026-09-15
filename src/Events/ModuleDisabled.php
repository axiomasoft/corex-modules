<?php

declare(strict_types=1);

namespace CoreX\Modules\Events;

use CoreX\Events\DomainEvent;
use CoreX\Modules\Enums\DisableReason;
use Override;

/**
 * Domain event dispatched when a module is disabled. Name matches the
 * domain-events table (`mod.module.disabled`).
 *
 * @internal spec: P1.25, A68, B-10 §3.1
 */
final class ModuleDisabled extends DomainEvent
{
    public function __construct(
        public readonly string $module,
        public readonly DisableReason $reason,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.disabled';
    }
}
