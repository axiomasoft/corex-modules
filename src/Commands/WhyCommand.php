<?php

declare(strict_types=1);

namespace CoreX\Modules\Commands;

use CoreX\Modules\Contracts\RegistryIntrospection;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Read-only local operator diagnostic for module activation status.
 */
final class WhyCommand extends Command
{
    protected $signature = 'corex:modules:why
                            {module : Composer package name of the module}
                            {--json : Emit machine-readable JSON}
                            {--account= : Trusted account id for tenant verdict}';

    protected $description = 'Explain why a module is or is not active for the current installation.';

    public function handle(RegistryIntrospection $introspection): int
    {
        $module = (string) $this->argument('module');
        $context = $this->resolveContext();
        $explanation = $introspection->why(module: $module, context: $context);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'schemaVersion' => $explanation->schemaVersion,
                'module' => $explanation->module,
                'status' => $explanation->status,
                'declaredConstraints' => $explanation->declaredConstraints,
                'reasons' => $explanation->reasons,
                'hasTenantVerdict' => $explanation->hasTenantVerdict,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line(sprintf('Module: %s', $explanation->module));
        $this->line(sprintf('Status: %s', $explanation->status));
        $this->line(sprintf('Tenant verdict: %s', $explanation->hasTenantVerdict ? 'yes' : 'no'));

        if (! $explanation->hasTenantVerdict) {
            $this->warn('No trusted tenant context supplied; activation verdict is declaration-only.');
        }

        $this->line('Reasons: '.implode(', ', $explanation->reasons));

        return self::SUCCESS;
    }

    private function resolveContext(): ?TenantContext
    {
        $accountId = $this->option('account');

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        return new TenantContext(new AccountRef(
            id: $accountId,
            slug: $accountId,
            status: 'active',
            features: [],
            limits: [],
        ));
    }
}
