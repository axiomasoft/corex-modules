<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Extend\EntityField;

/**
 * An EntityField extender resolved against the compiled entity set.
 * `sleeping` = declaring module differs from the target entity's owning
 * module: the declaration is collected here regardless, but only takes
 * effect for a tenant where the target module is active — that per-tenant
 * filter is ModuleRegistry's job, not this compiler's.
 *
 * @internal spec: P1.4, B-10 §7.4 п.5, P1.5
 */
final readonly class CompiledEntityField
{
    public function __construct(
        public EntityField $extender,
        public string $declaringModule,
        public bool $sleeping,
    ) {}

    /**
     * Plain-array projection for the compiled file. The wrapped
     * {@see EntityField} extender flattens through the shared
     * {@see CompiledRegistry::extenderToArray()} — no `__set_state`.
     *
     * @return array{extender: array<string, mixed>, declaringModule: string, sleeping: bool}
     *
     * @internal spec: P1.15
     */
    public function toArray(): array
    {
        return [
            'extender' => CompiledRegistry::extenderToArray($this->extender),
            'declaringModule' => $this->declaringModule,
            'sleeping' => $this->sleeping,
        ];
    }

    /**
     * @param  array{extender: array<string, mixed>, declaringModule: string, sleeping: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            extender: CompiledRegistry::extenderFromArray(EntityField::class, $data['extender']),
            declaringModule: $data['declaringModule'],
            sleeping: $data['sleeping'],
        );
    }
}
