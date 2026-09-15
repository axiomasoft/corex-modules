<?php

declare(strict_types=1);

namespace CoreX\Modules;

use CoreX\Contracts\FeatureFlagDefinition;
use CoreX\Modules\Exceptions\InvalidManifestException;
use CoreX\Modules\Extend\Extender;

/**
 * Fluent module manifest. A module's `manifest()` method builds one of
 * these; each setter mutates and returns $this so authors can chain
 * `Manifest::make(...)->title(...)->tablePrefix(...)`. Fields double as
 * public properties for read access (discovery/compiler/tests) — PHP allows
 * a property and a method to share a name, disambiguated by call syntax.
 *
 * @internal spec: B-10 §3.2
 */
final class Manifest
{
    private const COMPOSER_NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/';

    private const TABLE_PREFIX_PATTERN = '/^[a-z]{2,3}$/';

    public readonly string $composerName;

    public ?string $title = null;

    public ?string $description = null;

    public ?string $tablePrefix = null;

    /** @var list<Dep> */
    public array $requires = [];

    /** @var list<Dep> */
    public array $suggests = [];

    /** @var array<string, list<string>> */
    public array $editions = [];

    /** @var list<class-string> */
    public array $components = [];

    /** @var list<SettingDefault> */
    public array $settings = [];

    /**
     * @var list<Extender> RegistryCompiler pipeline input.
     *
     * @internal spec: B-10 §4/§4.2, P1.4
     */
    public array $extenders = [];

    /**
     * Retrofitted onto `CoreX\Contracts\FeatureFlagDefinition` —
     * `corex/core` ships the contract.
     *
     * @var list<FeatureFlagDefinition>
     *
     * @internal spec: D20/OQ-9 resolution, P1.9
     */
    public array $featureFlags = [];

    /** @var array<class-string, list<class-string>> */
    public array $listeners = [];

    public ?string $onEnableHook = null;

    public ?string $onDisableHook = null;

    public ?string $onPurgeHook = null;

    private function __construct(string $composerName)
    {
        if (preg_match(self::COMPOSER_NAME_PATTERN, $composerName) !== 1) {
            throw InvalidManifestException::invalidComposerName($composerName);
        }

        $this->composerName = $composerName;
    }

    public static function make(string $composerName): self
    {
        return new self($composerName);
    }

    public function title(string $title): self
    {
        if (trim($title) === '') {
            throw InvalidManifestException::emptyTitle($this->composerName);
        }

        $this->title = $title;

        return $this;
    }

    public function description(string $text): self
    {
        $this->description = $text;

        return $this;
    }

    public function tablePrefix(string $prefix): self
    {
        if (preg_match(self::TABLE_PREFIX_PATTERN, $prefix) !== 1) {
            throw InvalidManifestException::invalidTablePrefix($this->composerName, $prefix);
        }

        $this->tablePrefix = $prefix;

        return $this;
    }

    /** @param  list<Dep>  $deps */
    public function requires(array $deps): self
    {
        $this->requires = $deps;

        return $this;
    }

    /** @param  list<Dep>  $deps */
    public function suggests(array $deps): self
    {
        $this->suggests = $deps;

        return $this;
    }

    /** @param  array<string, list<string>>  $map */
    public function editions(array $map): self
    {
        $this->editions = $map;

        return $this;
    }

    /** @param  list<class-string>  $components */
    public function components(array $components): self
    {
        $this->components = $components;

        return $this;
    }

    /** @param  list<SettingDefault>  $defaults */
    public function settings(array $defaults): self
    {
        $this->settings = $defaults;

        return $this;
    }

    /** @param  list<Extender>  $extenders */
    public function extends(array $extenders): self
    {
        $this->extenders = $extenders;

        return $this;
    }

    /** @param  list<FeatureFlagDefinition>  $flags */
    public function featureFlags(array $flags): self
    {
        $this->featureFlags = $flags;

        return $this;
    }

    /** @param  array<class-string, list<class-string>>  $listeners */
    public function listens(array $listeners): self
    {
        $this->listeners = $listeners;

        return $this;
    }

    /** @param  class-string  $hookClass */
    public function onEnable(string $hookClass): self
    {
        $this->onEnableHook = $hookClass;

        return $this;
    }

    /** @param  class-string  $hookClass */
    public function onDisable(string $hookClass): self
    {
        $this->onDisableHook = $hookClass;

        return $this;
    }

    /** @param  class-string  $hookClass */
    public function onPurge(string $hookClass): self
    {
        $this->onPurgeHook = $hookClass;

        return $this;
    }
}
