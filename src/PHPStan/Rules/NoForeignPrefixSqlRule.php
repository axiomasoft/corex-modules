<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

use CoreX\Modules\PHPStan\Rules\Support\DatabaseCall;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Модуль не трогает таблицы чужого префикса напрямую — только через
 * контракты владельца.
 *
 * Владельца определяет конфиг `parameters.corexModules.modulePrefixes`
 * (PSR-4-неймспейс модуля → его `tablePrefix` из манифеста): статически связать
 * файл с манифестом нельзя, поэтому карта задаётся в neon пакета модуля. Код вне
 * карты (ядро, тесты, приложение) правило не трогает — ложных срабатываний нет.
 *
 * @implements Rule<CallLike>
 *
 * @internal spec: B-10 §7.4 п.9
 */
final class NoForeignPrefixSqlRule implements Rule
{
    /** Префиксы ядра — их не может трогать ни один модуль. */
    private const CORE_PREFIXES = ['sys', 'mod'];

    /**
     * Методы, чей первый строковый аргумент — ИМЯ ТАБЛИЦЫ целиком (не SQL-текст):
     * `DB::table()`/`Schema::*`. Матчится по полному совпадению строки, не regex-сканом.
     */
    private const TABLE_NAME_METHODS = ['table', 'from', 'create', 'drop', 'dropifexists', 'hastable', 'rename'];

    /**
     * @param  array<string, string>  $modulePrefixes  PSR-4-неймспейс модуля => tablePrefix
     */
    public function __construct(private readonly array $modulePrefixes) {}

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
        $ownPrefix = $this->ownPrefix($scope);

        if ($ownPrefix === null || ! DatabaseCall::matches($node)) {
            return [];
        }

        $known = array_values(array_unique([...self::CORE_PREFIXES, ...array_values($this->modulePrefixes)]));
        $errors = [];
        $tables = $this->isTableNameCall($node)
            ? $this->foreignTableLiterals($node, $scope, $ownPrefix, $known)
            : $this->foreignTablesInSql($node, $scope, $ownPrefix, $known);

        foreach ($tables as $table) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Direct DB access to table "%s" of a foreign prefix (module owns "%s_") is forbidden (B-10 §7.4 п.9) — go through the owner\'s contract.',
                $table,
                $ownPrefix,
            ))
                ->identifier('corexModules.foreignPrefixSql')
                ->build();
        }

        return $errors;
    }

    /**
     * `DB::table('x')`/`Schema::create('x', ...)` и т.п. — первый строковый аргумент
     * ЦЕЛИКОМ является именем таблицы, сравниваем без regex-скана по позициям.
     */
    private function isTableNameCall(CallLike $node): bool
    {
        $name = null;

        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $name = $node->name->toString();
        } elseif ($node instanceof MethodCall && $node->name instanceof Identifier) {
            $name = $node->name->toString();
        }

        return $name !== null && in_array(strtolower($name), self::TABLE_NAME_METHODS, true);
    }

    /**
     * @param  list<string>  $knownPrefixes
     * @return list<string>
     */
    private function foreignTableLiterals(CallLike $node, Scope $scope, string $ownPrefix, array $knownPrefixes): array
    {
        $args = $node->getArgs();

        if ($args === []) {
            return [];
        }

        // Только первый аргумент — второй/третий у Schema::rename()/create() не имена таблиц.
        $tables = [];

        foreach ($scope->getType($args[0]->value)->getConstantStrings() as $constantString) {
            $table = $constantString->getValue();

            if (preg_match('/^([a-z][a-z0-9]{0,2})_[a-z0-9_]+$/i', $table, $m) !== 1) {
                continue;
            }

            $prefix = strtolower($m[1]);

            if ($prefix !== $ownPrefix && in_array($prefix, $knownPrefixes, true)) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Raw-SQL методы (`DB::select`/`DB::statement`/…): чужой префикс детектится ТОЛЬКО
     * на позиции имени таблицы — токен, идущий сразу за `FROM`/`JOIN`/`INTO`/`UPDATE`/`TABLE`.
     * Любое другое вхождение `xx_yyy` (колонка, алиас, строковое значение) — легально.
     *
     * @param  list<string>  $knownPrefixes
     * @return list<string>
     */
    private function foreignTablesInSql(CallLike $node, Scope $scope, string $ownPrefix, array $knownPrefixes): array
    {
        $tables = [];

        foreach (DatabaseCall::constantStringArguments($node, $scope) as $sql) {
            if (preg_match_all(
                '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+([a-z][a-z0-9]{0,2})_[a-z0-9_]+\b/i',
                $sql,
                $matches,
                PREG_SET_ORDER,
            ) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $table = preg_replace('/^\S+\s+/', '', $match[0]) ?? $match[0];
                $prefix = strtolower($match[1]);

                if ($prefix !== $ownPrefix && in_array($prefix, $knownPrefixes, true)) {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    private function ownPrefix(Scope $scope): ?string
    {
        $namespace = $scope->getClassReflection()?->getName() ?? $scope->getNamespace();

        if ($namespace === null) {
            return null;
        }

        foreach ($this->modulePrefixes as $moduleNamespace => $prefix) {
            if (str_starts_with($namespace, rtrim($moduleNamespace, '\\').'\\')) {
                return $prefix;
            }
        }

        return null;
    }
}
