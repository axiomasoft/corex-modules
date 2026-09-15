<?php

declare(strict_types=1);

namespace CoreX\Modules\Events;

use CoreX\Events\DomainEvent;
use Override;

/**
 * Retrofitted onto `DomainEvent` (see {@see ModuleEnabled}). `mod.module.purged`.
 *
 * @internal spec: P1.25, A68, B-10 §3.1
 */
final class ModulePurged extends DomainEvent
{
    /** @param  list<string>  $droppedTables */
    public function __construct(
        public readonly string $module,
        public readonly array $droppedTables,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'mod.module.purged';
    }
}
