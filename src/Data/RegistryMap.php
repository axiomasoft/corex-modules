<?php

declare(strict_types=1);

namespace CoreX\Modules\Data;

final readonly class RegistryMap
{
    /**
     * @param  list<array<string, mixed>>  $extensionPoints
     */
    public function __construct(
        public string $schemaVersion,
        public string $revision,
        public array $extensionPoints,
    ) {}
}
