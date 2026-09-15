<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use CoreX\Modules\ModuleContext;

/**
 * Module-authored non-standard cleanup on purge — external webhooks, media
 * files — anything outside what `mod_records` can drop by itself.
 *
 * @internal spec: B-10 §5.4
 */
interface PurgeHook
{
    public function __invoke(ModuleContext $ctx): void;
}
