<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

use CoreX\Modules\PHPStan\Rules\Support\DatabaseCall;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * База правил, читающих SQL из строковых аргументов DB/Schema-вызовов.
 *
 * Запреты консервативнее фактических возможностей инфраструктуры: PgBouncer ≥1.21
 * с `max_prepared_statements` проносит protocol-level PREPARE, а NOTIFY (без LISTEN)
 * переживает transaction pooling. Ядро запрещает это целиком — код
 * модуля не должен зависеть от версии и настроек пулера.
 *
 * @implements Rule<CallLike>
 *
 * @internal spec: B-10 §7.3
 */
abstract class AbstractDatabaseCallRule implements Rule
{
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @param  CallLike  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! DatabaseCall::matches($node)) {
            return [];
        }

        $errors = [];

        foreach (DatabaseCall::constantStringArguments($node, $scope) as $sql) {
            if (! $this->isViolation($sql)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message($this->message())
                ->identifier($this->identifier())
                ->build();
        }

        return $errors;
    }

    abstract protected function isViolation(string $sql): bool;

    abstract protected function message(): string;

    abstract protected function identifier(): string;

    /**
     * @return list<string>
     */
    protected function statements(string $sql): array
    {
        return DatabaseCall::statements($sql);
    }
}
