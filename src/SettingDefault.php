<?php

declare(strict_types=1);

namespace CoreX\Modules;

/**
 * Manifest-declared setting default: namespace is the owning module id,
 * applied automatically by the settings cascade as the lowest-priority
 * level below user/workspace/account overrides.
 *
 * @internal spec: B-10 §3.2, P1.7
 */
final readonly class SettingDefault
{
    private function __construct(
        public string $key,
        public mixed $default,
        public bool $workspaceScoped = false,
        public bool $userScoped = false,
        public bool $sensitive = false,
    ) {}

    public static function make(string $key, mixed $default = null): self
    {
        return new self($key, $default);
    }

    public function workspaceScoped(): self
    {
        return new self(
            key: $this->key,
            default: $this->default,
            workspaceScoped: true,
            userScoped: $this->userScoped,
            sensitive: $this->sensitive,
        );
    }

    public function userScoped(): self
    {
        return new self(
            key: $this->key,
            default: $this->default,
            workspaceScoped: $this->workspaceScoped,
            userScoped: true,
            sensitive: $this->sensitive,
        );
    }

    public function sensitive(): self
    {
        return new self(
            key: $this->key,
            default: $this->default,
            workspaceScoped: $this->workspaceScoped,
            userScoped: $this->userScoped,
            sensitive: true,
        );
    }
}
