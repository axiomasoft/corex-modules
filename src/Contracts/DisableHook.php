<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use CoreX\Modules\ModuleContext;

/**
 * Module-authored teardown of non-data side effects on disable (cron,
 * webhooks). MUST NOT touch the module's data.
 *
 * @internal spec: B-10 §5.4, §7.4 п.1
 */
interface DisableHook
{
    public function __invoke(ModuleContext $ctx): void;
}
