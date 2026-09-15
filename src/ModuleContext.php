<?php

declare(strict_types=1);

namespace CoreX\Modules;

use CoreX\Modules\Contracts\RecordsRegistrar;
use CoreX\Tenancy\TenantContext;

/**
 * Payload passed to {@see Contracts\EnableHook}/{@see Contracts\DisableHook}/
 * {@see Contracts\PurgeHook}: which module, for which tenant, with the
 * registrar the hook must use to claim/release anything it creates.
 *
 * @internal spec: B-10 §3.3
 */
final readonly class ModuleContext
{
    public function __construct(
        public string $module,
        public TenantContext $tenant,
        public RecordsRegistrar $records,
    ) {}
}
