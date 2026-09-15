<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Exceptions\CompilationException;
use CoreX\Modules\Extend\Api;
use JsonException;

/** @internal Validates serializable declarations without depending on an API runtime. */
final class ApiOperationValidator
{
    public static function validate(Api $api): void
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $api->operationId) !== 1
            || preg_match('/^v[1-9][0-9]*$/D', $api->version) !== 1
            || ! in_array($api->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || preg_match('#^/(?:[a-zA-Z0-9_.-]+|\{[a-zA-Z_][a-zA-Z0-9_]*\})(?:/(?:[a-zA-Z0-9_.-]+|\{[a-zA-Z_][a-zA-Z0-9_]*\}))*$#D', $api->path) !== 1
            || trim($api->ability) === '' || $api->handler === null
            || ! class_exists($api->handler)
            || $api->successStatus < 200 || $api->successStatus > 299 || $api->successStatus === 204) {
            throw new CompilationException('Invalid API operation declaration: '.$api->operationId);
        }

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $api->path, $parameters);

        if (count($parameters[1]) !== count(array_unique($parameters[1]))) {
            throw new CompilationException('Repeated API path parameter: '.$api->operationId);
        }

        self::validateRules($api->inputRules);

        self::assertJsonValue($api->requestSchema);
        self::assertJsonValue($api->responseSchema);

        try {
            json_encode([$api->requestSchema, $api->responseSchema], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new CompilationException('Invalid API schema: '.$api->operationId, previous: $e);
        }
    }

    public static function validateRoutePair(Api $first, Api $second): void
    {
        if ($first->version !== $second->version) {
            return;
        }

        $normalize = static fn (string $path): string => (string) preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '{}', $path);

        // OpenAPI permits one template at a hierarchy, regardless of HTTP method.
        if ($normalize($first->path) === $normalize($second->path) && $first->path !== $second->path) {
            throw new CompilationException('Equivalent API templates must use the same parameter names.');
        }

        if ($first->method !== $second->method || substr_count($first->path, '{') !== substr_count($second->path, '{')) {
            return;
        }

        $firstSegments = explode('/', $first->path);
        $secondSegments = explode('/', $second->path);

        if (count($firstSegments) !== count($secondSegments)) {
            return;
        }

        foreach ($firstSegments as $index => $segment) {
            if ($segment !== $secondSegments[$index] && ! str_starts_with($segment, '{') && ! str_starts_with($secondSegments[$index], '{')) {
                return;
            }
        }

        throw new CompilationException('Ambiguous API routes with equal specificity.');
    }

    private static function validateRules(mixed $rules): void
    {
        if (! is_array($rules)) {
            throw new CompilationException('API validation rules must be an array.');
        }

        foreach ($rules as $field => $list) {
            if (! is_string($field) || $field === '' || ! is_array($list) || ! array_is_list($list)) {
                throw new CompilationException('Invalid API validation field or rule list.');
            }

            foreach ($list as $rule) {
                if (! is_string($rule)) {
                    throw new CompilationException('API validation rules must be strings.');
                }
            }
        }
    }

    private static function assertJsonValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJsonValue($item);
            }
        } elseif (! is_scalar($value) && $value !== null) {
            throw new CompilationException('API schemas must contain only JSON values.');
        }
    }
}
