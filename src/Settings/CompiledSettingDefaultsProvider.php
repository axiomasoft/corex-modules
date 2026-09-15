<?php

declare(strict_types=1);

namespace CoreX\Modules\Settings;

use CoreX\Contracts\SettingDefaultsProvider;
use CoreX\Modules\Compiler\CompiledSettingDefault;
use CoreX\Modules\Contracts\ModuleRegistry;
use CoreX\Modules\SettingDefault;
use CoreX\Settings\NullSettingDefaultsProvider;
use Override;

/**
 * Real {@see SettingDefaultsProvider} for corex/modules — direction
 * core←modules stays intact: `corex/core` only ever sees the contract, this
 * implementation lives here and `ModulesServiceProvider` rebinds it over the
 * boxed {@see NullSettingDefaultsProvider} default. Reads manifest-declared
 * {@see SettingDefault}s off the COMPILED registry
 * ({@see ModuleRegistry::compiled()}) — never off `ModuleDiscovery::discover()`,
 * which would re-parse every manifest's `manifest()` method on this hot path
 * (`DatabaseSettingsRepository::get()` calls `defaultFor()` on every
 * uncached read).
 *
 * @internal spec: D20/D22/D23, P1.15, P1.25
 */
final class CompiledSettingDefaultsProvider implements SettingDefaultsProvider
{
    /** @var array<string, array<string, CompiledSettingDefault>>|null keyed by [namespace][key], built once per instance */
    private ?array $index = null;

    public function __construct(private readonly ModuleRegistry $registry) {}

    #[Override]
    public function defaultFor(string $namespace, string $key): mixed
    {
        return $this->index()[$namespace][$key]->definition->default ?? null;
    }

    #[Override]
    public function isSensitive(string $namespace, string $key): bool
    {
        return $this->index()[$namespace][$key]->definition->sensitive ?? false;
    }

    /** @return array<string, array<string, CompiledSettingDefault>> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->registry->compiled()->settings as $setting) {
            $index[$setting->namespace][$setting->definition->key] = $setting;
        }

        return $this->index = $index;
    }
}
