<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

use CoreX\Modules\Registry\FieldDefinition;

/**
 * 1. Declares an owned entity into EntityRegistry. `handle` defaults to
 * `{tablePrefix}.{snake(model)}` and `fields` to model `$casts`
 * introspection when left unset — both resolved by RegistryCompiler, which
 * knows the owning manifest this extender belongs to.
 *
 * @internal spec: B-10 §4.2
 */
final class Entity implements Extender
{
    /** @var class-string */
    public string $model;

    public ?string $handle = null;

    public ?string $table = null;

    public ?string $labelSingular = null;

    public ?string $labelPlural = null;

    public ?string $titleAttribute = null;

    public bool $isSearchable = false;

    public bool $isAuditable = false;

    public bool $hasCustomFields = false;

    public bool $isWorkflowable = false;

    public bool $isCommentable = false;

    public bool $isDocumentable = false;

    /** @var list<FieldDefinition>|null null = introspect model */
    public ?array $fieldDefinitions = null;

    private function __construct(string $model)
    {
        $this->model = $model;
    }

    /** @param  class-string  $model */
    public static function make(string $model): self
    {
        return new self($model);
    }

    public function handle(string $handle): self
    {
        $this->handle = $handle;

        return $this;
    }

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function label(string $singular, string $plural): self
    {
        $this->labelSingular = $singular;
        $this->labelPlural = $plural;

        return $this;
    }

    public function titleAttribute(string $attr): self
    {
        $this->titleAttribute = $attr;

        return $this;
    }

    public function searchable(): self
    {
        $this->isSearchable = true;

        return $this;
    }

    public function auditable(): self
    {
        $this->isAuditable = true;

        return $this;
    }

    public function customFields(): self
    {
        $this->hasCustomFields = true;

        return $this;
    }

    public function workflowable(): self
    {
        $this->isWorkflowable = true;

        return $this;
    }

    public function commentable(): self
    {
        $this->isCommentable = true;

        return $this;
    }

    public function documentable(): self
    {
        $this->isDocumentable = true;

        return $this;
    }

    /** @param  list<FieldDefinition>  $fields */
    public function fields(array $fields): self
    {
        $this->fieldDefinitions = $fields;

        return $this;
    }
}
