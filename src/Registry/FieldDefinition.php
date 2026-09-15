<?php

declare(strict_types=1);

namespace CoreX\Modules\Registry;

/**
 * System field of an entity; custom fields live in an external attributes package.
 *
 * @internal spec: B-10 §3.4
 */
final readonly class FieldDefinition
{
    /**
     * @param  'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum'  $type
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public string $handle,
        public string $type,
        public bool $required = false,
        public bool $searchable = false,
        public ?array $meta = null,
    ) {}

    /**
     * Plain-array projection for {@see CompiledRegistry} persistence — scalars
     * only, no `__set_state`: the compiled file must be var_export'able and
     * re-readable without reconstructing objects from serialized state.
     *
     * @return array{handle: string, type: string, required: bool, searchable: bool, meta: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'type' => $this->type,
            'required' => $this->required,
            'searchable' => $this->searchable,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param  array{handle: string, type: 'string'|'int'|'decimal'|'bool'|'date'|'datetime'|'json'|'money'|'relation'|'enum', required?: bool, searchable?: bool, meta?: array<string, mixed>|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            handle: $data['handle'],
            type: $data['type'],
            required: $data['required'] ?? false,
            searchable: $data['searchable'] ?? false,
            meta: $data['meta'] ?? null,
        );
    }
}
