<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

/**
 * 3. API declaration: resource metadata, explicit operations, or an added
 * attribute on ANOTHER module's resource (narrow analogue of Flarum
 * ApiSerializer — no controller override).
 *
 * @internal spec: B-10 §4.2, §4.3 п.3
 */
final class Api implements Extender
{
    public string $entityHandle = '';

    /** @var 'entity'|'extendResource'|'operation'|'tool' */
    public string $mode;

    public ?string $declaringModule = null;

    public string $operationId = '';

    public string $version = 'v1';

    public string $method = 'GET';

    public string $path = '';

    public string $ability = '';

    /** @var class-string|null */
    public ?string $handler = null;

    /** @var array<string, mixed> */
    public array $requestSchema = ['type' => 'object'];

    /** @var array<string, mixed> */
    public array $responseSchema = ['type' => 'object'];

    /** @var array<string, list<string>> Laravel validation rules; no closures or objects in the compiled file. */
    public array $inputRules = [];

    public int $successStatus = 200;

    public bool $isReadonly = false;

    /** @var list<string>|null */
    public ?array $onlyFields = null;

    /** @var list<string> */
    public array $includes = [];

    /** @var array<string, class-string> attribute name => class-string<ApiAttributeResolver> */
    public array $attributes = [];

    public string $toolCode = '';

    public string $toolVersion = 'v1';

    public string $sideEffect = '';

    public string $idempotency = '';

    /** @var array<string, mixed> */
    public array $availability = [];

    public string $title = '';

    public string $description = '';

    /** @var 'declared'|'exposed' */
    public string $toolKind = 'declared';

    public ?string $operationRef = null;

    /** @var array<string, string> */
    public array $parameterMap = [];

    private function __construct()
    {
        //
    }

    public static function entity(string $entityHandle): self
    {
        $api = new self;
        $api->entityHandle = $entityHandle;
        $api->mode = 'entity';

        return $api;
    }

    /**
     * @param  class-string  $handler
     * @param  array<string, mixed>  $requestSchema
     * @param  array<string, mixed>  $responseSchema
     * @param  array<string, list<string>>  $rules
     */
    public static function operation(
        string $id,
        string $method,
        string $path,
        string $handler,
        string $ability,
        array $requestSchema,
        array $responseSchema,
        array $rules = [],
        string $version = 'v1',
        int $successStatus = 200,
    ): self {
        $api = new self;
        $api->mode = 'operation';
        $api->operationId = $id;
        $api->method = strtoupper($method);
        $api->path = $path;
        $api->handler = $handler;
        $api->ability = $ability;
        $api->requestSchema = $requestSchema;
        $api->responseSchema = $responseSchema;
        $api->inputRules = $rules;
        $api->version = $version;
        $api->successStatus = $successStatus;

        return $api;
    }

    public static function extendResource(string $entityHandle): self
    {
        $api = new self;
        $api->entityHandle = $entityHandle;
        $api->mode = 'extendResource';

        return $api;
    }

    public function readonly(): self
    {
        $this->isReadonly = true;

        return $this;
    }

    /** @param  list<string>  $only */
    public function fields(array $only): self
    {
        $this->onlyFields = $only;

        return $this;
    }

    public function include(string ...$relations): self
    {
        $this->includes = array_values($relations);

        return $this;
    }

    /** @param  class-string  $resolverClass */
    public function attribute(string $name, string $resolverClass): self
    {
        $this->attributes[$name] = $resolverClass;

        return $this;
    }

    /**
     * @param  class-string  $handler
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     * @param  array<string, mixed>  $availability
     */
    public static function tool(
        string $code,
        string $handler,
        string $ability,
        array $inputSchema,
        array $outputSchema,
        string $sideEffect,
        string $idempotency,
        string $toolVersion = 'v1',
        array $availability = [],
    ): self {
        $api = new self;
        $api->mode = 'tool';
        $api->toolKind = 'declared';
        $api->toolCode = $code;
        $api->handler = $handler;
        $api->ability = $ability;
        $api->requestSchema = $inputSchema;
        $api->responseSchema = $outputSchema;
        $api->sideEffect = $sideEffect;
        $api->idempotency = $idempotency;
        $api->toolVersion = $toolVersion;
        $api->availability = $availability;

        return $api;
    }

    /**
     * @param  array<string, mixed>  $argumentSchema
     * @param  array<string, mixed>  $resultSchema
     * @param  array<string, string>  $parameterMap
     */
    public static function exposeTool(
        string $operationId,
        string $operationVersion,
        string $code,
        string $toolVersion,
        string $sideEffect,
        string $idempotency,
        array $argumentSchema,
        array $resultSchema,
        array $parameterMap = [],
    ): self {
        $api = new self;
        $api->mode = 'tool';
        $api->toolKind = 'exposed';
        $api->toolCode = $code;
        $api->operationRef = $operationVersion.':'.$operationId;
        $api->requestSchema = $argumentSchema;
        $api->responseSchema = $resultSchema;
        $api->sideEffect = $sideEffect;
        $api->idempotency = $idempotency;
        $api->toolVersion = $toolVersion;
        $api->parameterMap = $parameterMap;

        return $api;
    }

    public function titled(string $title, string $description = ''): self
    {
        $this->title = $title;
        $this->description = $description;

        return $this;
    }
}
