<?php

declare(strict_types=1);

namespace CoreX\Modules\Registry;

use CoreX\Modules\Compiler\CompiledRegistry;
use CoreX\Modules\Contracts\ModuleLifecycle;
use CoreX\Modules\Dep;
use CoreX\Modules\Manifest;

/**
 * One module's compiled-registry entry (`ModuleRegistry::get()`): manifest
 * metadata needed at the hot path, serialized as plain scalars/arrays so it
 * round-trips through `var_export()` atomic write.
 *
 * Compiled extender output (entities, permissions, menu, api, filament,
 * routes, workflow — {@see CompiledRegistry}) is persisted separately in the
 * same compiled file under its own `registry` key. This entry keeps the
 * per-module manifest metadata the hot path and lifecycle need: identity,
 * table prefix, dependency/edition/component maps and the terminal-transition
 * hooks {@see ModuleLifecycle} fires.
 *
 * @internal spec: B-10 §3.3, P1.5, P1.15, P1.18
 */
final readonly class CompiledModule
{
    /**
     * @param  list<array{type: string, target: string, constraint: string}>  $requires
     * @param  array<string, list<string>>  $editions
     * @param  list<class-string>  $components
     * @param  class-string|null  $onEnableHook
     * @param  class-string|null  $onDisableHook
     * @param  class-string|null  $onPurgeHook
     */
    public function __construct(
        public string $composerName,
        public ?string $title,
        public ?string $tablePrefix,
        public array $requires,
        public array $editions,
        public array $components,
        public ?string $onEnableHook = null,
        public ?string $onDisableHook = null,
        public ?string $onPurgeHook = null,
    ) {}

    public static function fromManifest(Manifest $manifest): self
    {
        return new self(
            composerName: $manifest->composerName,
            title: $manifest->title,
            tablePrefix: $manifest->tablePrefix,
            requires: array_map(
                static fn (Dep $dep): array => ['type' => $dep->type, 'target' => $dep->target, 'constraint' => $dep->constraint],
                $manifest->requires,
            ),
            editions: $manifest->editions,
            components: $manifest->components,
            onEnableHook: $manifest->onEnableHook,
            onDisableHook: $manifest->onDisableHook,
            onPurgeHook: $manifest->onPurgeHook,
        );
    }

    /**
     * @param  array{composerName: string, title: ?string, tablePrefix: ?string, requires: list<array{type: string, target: string, constraint: string}>, editions: array<string, list<string>>, components: list<class-string>, onEnableHook?: class-string|null, onDisableHook?: class-string|null, onPurgeHook?: class-string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            composerName: $data['composerName'],
            title: $data['title'],
            tablePrefix: $data['tablePrefix'],
            requires: $data['requires'],
            editions: $data['editions'],
            components: $data['components'],
            onEnableHook: $data['onEnableHook'] ?? null,
            onDisableHook: $data['onDisableHook'] ?? null,
            onPurgeHook: $data['onPurgeHook'] ?? null,
        );
    }

    /**
     * @return array{composerName: string, title: ?string, tablePrefix: ?string, requires: list<array{type: string, target: string, constraint: string}>, editions: array<string, list<string>>, components: list<class-string>, onEnableHook: class-string|null, onDisableHook: class-string|null, onPurgeHook: class-string|null}
     */
    public function toArray(): array
    {
        return [
            'composerName' => $this->composerName,
            'title' => $this->title,
            'tablePrefix' => $this->tablePrefix,
            'requires' => $this->requires,
            'editions' => $this->editions,
            'components' => $this->components,
            'onEnableHook' => $this->onEnableHook,
            'onDisableHook' => $this->onDisableHook,
            'onPurgeHook' => $this->onPurgeHook,
        ];
    }
}
