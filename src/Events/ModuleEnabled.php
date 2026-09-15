<?php

declare(strict_types=1);

namespace CoreX\Modules\Events;

use CoreX\Events\DomainEvent;
use Override;

/**
 * Retrofitted onto `DomainEvent` — was an interim plain-event class
 * dispatched on a successful `ModuleLifecycle::enable()`. Name matches the
 * domain-events table (`mod.module.enabled`).
 *
 * @internal spec: P1.25, A68, OQ-4 workaround, B-10 §3.1
 */
final class ModuleEnabled extends DomainEvent
{
    /** @param  list<string>  $migrationsRun */
    public function __construct(
        public readonly string $module,
        public readonly string $edition,
        public readonly array $migrationsRun,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.enabled';
    }
}
