<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Exceptions\CompilationException;
use CoreX\Modules\Extend\Api;
use JsonException;

/** @internal Validates compiled tool declarations without depending on tool runtime. */
final class ApiToolValidator
{
    private const OPERATION_HANDLER = 'CoreX\\Api\\Contracts\\OperationHandler';

    private const SIDE_EFFECTS = ['read', 'draft', 'write', 'irreversible'];

    private const IDEMPOTENCY = ['read-only', 'domain-key', 'provider-key'];

    public static function validate(Api $api): void
    {
        if ($api->mode !== 'tool') {
            throw new CompilationException('Expected tool declaration.');
        }

        if (! preg_match('/^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$/', $api->toolCode)) {
            throw new CompilationException('Invalid canonical tool code: '.$api->toolCode);
        }

        [, $verb] = explode('.', $api->toolCode, 2);

        if (str_contains($verb, '__v')) {
            throw new CompilationException('Canonical tool verb reserves __v suffix: '.$api->toolCode);
        }

        if (preg_match('/^v[1-9][0-9]*$/', $api->toolVersion) !== 1) {
            throw new CompilationException('Invalid tool version: '.$api->toolVersion);
        }

        if (! in_array($api->sideEffect, self::SIDE_EFFECTS, true)) {
            throw new CompilationException('Invalid tool side effect: '.$api->toolCode);
        }

        if (! in_array($api->idempotency, self::IDEMPOTENCY, true)) {
            throw new CompilationException('Invalid tool idempotency: '.$api->toolCode);
        }

        if ($api->sideEffect === 'read' && $api->idempotency !== 'read-only') {
            throw new CompilationException('Read tools require read-only idempotency: '.$api->toolCode);
        }

        if ($api->sideEffect !== 'read' && ! in_array($api->idempotency, ['domain-key', 'provider-key'], true)) {
            throw new CompilationException('Mutation tools require domain-key or provider-key idempotency: '.$api->toolCode);
        }

        ApiOperationValidator::assertJsonValue($api->requestSchema);
        ApiOperationValidator::assertJsonValue($api->responseSchema);

        try {
            json_encode([$api->requestSchema, $api->responseSchema, $api->availability], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CompilationException('Invalid tool schema: '.$api->toolCode, previous: $exception);
        }

        if ($api->toolKind === 'declared') {
            self::validateDeclaredTool($api);
        } else {
            self::validateExposedTool($api);
        }
    }

    public static function validateExposedOperationReference(Api $tool, Api $operation, string $declaringModule): void
    {
        if ($tool->operationRef === null) {
            throw new CompilationException('Exposed tool missing operation reference: '.$tool->toolCode);
        }

        if ($operation->declaringModule !== $declaringModule) {
            throw new CompilationException('Foreign operation reference for tool: '.$tool->toolCode);
        }

        [$version, $operationId] = explode(':', $tool->operationRef, 2);

        if ($operation->version !== $version || $operation->operationId !== $operationId) {
            throw new CompilationException('Unresolved operation reference for tool: '.$tool->toolCode);
        }

        if ($tool->handler === null) {
            $tool->handler = $operation->handler;
        }

        if ($tool->ability === '') {
            $tool->ability = $operation->ability;
        }

        foreach ($tool->parameterMap as $argument => $parameter) {
            if ($argument === '' || $parameter === '') {
                throw new CompilationException('Invalid parameter map for tool: '.$tool->toolCode);
            }
        }
    }

    public static function wireName(Api $api): string
    {
        return str_replace('.', '_', $api->toolCode).'__v'.substr($api->toolVersion, 1);
    }

    private static function validateDeclaredTool(Api $api): void
    {
        if (trim($api->ability) === '' || $api->handler === null || ! class_exists($api->handler)) {
            throw new CompilationException('Tool handler missing for: '.$api->toolCode);
        }

        if (interface_exists(self::OPERATION_HANDLER) && ! is_a($api->handler, self::OPERATION_HANDLER, true)) {
            throw new CompilationException('Tool handler must implement OperationHandler: '.$api->toolCode);
        }
    }

    private static function validateExposedTool(Api $api): void
    {
        if ($api->operationRef === null) {
            throw new CompilationException('Exposed tool missing operation reference: '.$api->toolCode);
        }

        if ($api->parameterMap === []) {
            throw new CompilationException('Exposed tool requires explicit parameter map: '.$api->toolCode);
        }
    }
}
