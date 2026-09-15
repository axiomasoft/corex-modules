<?php

declare(strict_types=1);

namespace CoreX\Modules\Contracts;

use CoreX\Modules\Gate\AllowAllActivationGate;
use CoreX\Modules\GateDecision;
use CoreX\Tenancy\TenantContext;

/**
 * Cloud billing/edition gate. Boxed installs bind
 * {@see AllowAllActivationGate} — the cloud implementation (root_ billing)
 * is injected by the application, never known to this package.
 *
 * @internal spec: B-10 §3.3
 */
interface ModuleActivationGate
{
    public function check(TenantContext $ctx, string $module, string $edition): GateDecision;
}
