<?php

declare(strict_types=1);

namespace CoreX\Modules\Data;

final readonly class ModuleExplanation
{
    /**
     * @param  array<string, null|bool|int|string|array<int|string, mixed>>  $declaredConstraints
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $schemaVersion,
        public string $module,
        public string $status,
        public array $declaredConstraints,
        public array $reasons,
        public bool $hasTenantVerdict,
    ) {}
}
