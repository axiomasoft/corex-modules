<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

use CoreX\Modules\PHPStan\Rules\Support\DatabaseCall;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Писать в `sys_*`/`mod_*` можно только через контракты ядра
 * (Settings/Audit/ModuleLifecycle/…), не запросом из модуля.
 *
 * Владельцы контрактов (`parameters.corexModules.systemTableNamespaces`) исключены —
 * именно они и есть легальная реализация записи.
 *
 * @implements Rule<CallLike>
 *
 * @internal spec: B-10 §7.4 п.9
 */
final class NoDirectSystemTableWriteRule implements Rule
{
    private const WRITE_METHODS = [
        'insert', 'insertgetid', 'insertorignore', 'update', 'updateorinsert',
        'upsert', 'delete', 'truncate',
    ];

    private const SCHEMA_METHODS = [
        'create', 'table', 'drop', 'dropifexists', 'rename',
    ];

    private const SYSTEM_TABLE = '/^(sys|mod)_[a-z0-9_]+$/i';

    private const WRITE_SQL = '/\b(?:insert\s+into|update|delete\s+from|alter\s+table|drop\s+table|truncate(?:\s+table)?)\s+"?((?:sys|mod)_[a-z0-9_]+)/i';

    /**
     * @param  list<string>  $systemTableNamespaces  неймспейсы-владельцы контрактов ядра
     */
    public function __construct(private readonly array $systemTableNamespaces) {}

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
        if ($this->isCoreOwner($scope) || $this->isMigrationFile($scope)) {
            return [];
        }

        $table = $this->writtenSystemTable($node, $scope);

        if ($table === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Direct write to core table "%s" is forbidden (B-10 §7.4 п.9) — use the owning core contract.',
                $table,
            ))
                ->identifier('corexModules.directSystemTableWrite')
                ->build(),
        ];
    }

    private function writtenSystemTable(CallLike $node, Scope $scope): ?string
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return null;
        }

        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = strtolower($node->name->toString());

        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && strtolower($node->class->getLast()) === 'schema'
            && in_array($method, self::SCHEMA_METHODS, true)
        ) {
            return $this->firstSystemTableArgument($node, $scope);
        }

        if (DatabaseCall::matches($node)) {
            foreach (DatabaseCall::constantStringArguments($node, $scope) as $sql) {
                if (preg_match(self::WRITE_SQL, $sql, $matches) === 1) {
                    return $matches[1];
                }
            }
        }

        if ($node instanceof MethodCall && in_array($method, self::WRITE_METHODS, true)) {
            return $this->systemTableInChain($node->var, $scope);
        }

        return null;
    }

    private function firstSystemTableArgument(CallLike $node, Scope $scope): ?string
    {
        foreach (DatabaseCall::constantStringArguments($node, $scope) as $value) {
            if (preg_match(self::SYSTEM_TABLE, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Ищет `->table('sys_…')`/`->from('mod_…')` в цепочке получателя write-вызова.
     */
    private function systemTableInChain(Expr $expr, Scope $scope): ?string
    {
        while ($expr instanceof MethodCall || $expr instanceof StaticCall) {
            if ($expr->name instanceof Identifier
                && in_array(strtolower($expr->name->toString()), ['table', 'from'], true)
            ) {
                $table = $this->firstSystemTableArgument($expr, $scope);

                if ($table !== null) {
                    return $table;
                }
            }

            $expr = $expr instanceof MethodCall ? $expr->var : null;

            if ($expr === null) {
                return null;
            }
        }

        return null;
    }

    /**
     * Миграции — anonymous `return new class extends Migration {…}` без `namespace`
     * (обязательное соглашение пакетных миграций — не коллидировать с миграцией
     * потребителя), поэтому `isCoreOwner()` (по неймспейсу) их не видит. Миграция —
     * легальный владелец таблицы, которую сама создаёт: она и есть DDL-источник
     * истины для `sys_*`/`mod_*`, отличный от рантайм-контрактов (Settings/Audit/…).
     */
    private function isMigrationFile(Scope $scope): bool
    {
        return str_contains(str_replace('\\', '/', $scope->getFile()), '/database/migrations/');
    }

    private function isCoreOwner(Scope $scope): bool
    {
        $namespace = $scope->getClassReflection()?->getName() ?? $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        foreach ($this->systemTableNamespaces as $coreNamespace) {
            if (str_starts_with($namespace, rtrim($coreNamespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }
}
