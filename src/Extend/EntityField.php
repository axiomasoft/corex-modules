<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

/**
 * 2. A field/relation attached to ANOTHER module's entity. Storage goes
 * through an external attributes package — this only declares the shape;
 * altering the owning entity's own table is forbidden. When the target
 * entity belongs to a different module than the declaring manifest,
 * RegistryCompiler marks the compiled declaration "sleeping" — it only
 * takes effect for a tenant where the target module is active.
 *
 * @internal spec: B-10 §4.2, B-15
 */
final class EntityField implements Extender
{
    public string $entityHandle;

    public string $fieldHandle;

    /** @var 'scalar'|'relation' */
    public string $kind;

    public ?string $type = null;

    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var 'hasMany'|'belongsTo'|'belongsToMany'|null */
    public ?string $relationType = null;

    /** @var class-string|null */
    public ?string $related = null;

    public bool $isRequired = false;

    public bool $isSearchable = false;

    private function __construct()
    {
        //
    }

    /** @param  array<string, mixed>  $meta */
    public static function scalar(string $entityHandle, string $fieldHandle, string $type, array $meta = []): self
    {
        $field = new self;
        $field->entityHandle = $entityHandle;
        $field->fieldHandle = $fieldHandle;
        $field->kind = 'scalar';
        $field->type = $type;
        $field->meta = $meta;

        return $field;
    }

    /** @param  class-string  $related */
    public static function relation(string $entityHandle, string $fieldHandle, string $relationType, string $related): self
    {
        $field = new self;
        $field->entityHandle = $entityHandle;
        $field->fieldHandle = $fieldHandle;
        $field->kind = 'relation';
        $field->relationType = $relationType;
        $field->related = $related;

        return $field;
    }

    public function required(): self
    {
        $this->isRequired = true;

        return $this;
    }

    public function searchable(): self
    {
        $this->isSearchable = true;

        return $this;
    }
}
