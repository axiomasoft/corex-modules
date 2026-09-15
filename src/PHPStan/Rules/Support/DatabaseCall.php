<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules\Support;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

/**
 * Распознавание обращений к БД (`DB::`/`Schema::`, методы с SQL-аргументом) и
 * извлечение из них строк-констант. Общая часть правил corex/modules-lint.
 */
final class DatabaseCall
{
    /** Фасады, любой вызов которых считается обращением к БД. */
    private const FACADES = ['db', 'schema'];

    /** Методы, чей строковый аргумент считается SQL (на любом получателе). */
    private const SQL_METHODS = [
        'statement', 'unprepared', 'raw', 'select', 'selectone', 'selectresultsets',
        'scalar', 'cursor', 'exec', 'query', 'prepare', 'insert', 'update', 'delete',
    ];

    public static function matches(CallLike $node): bool
    {
        if ($node instanceof StaticCall && $node->class instanceof Name && self::isFacade($node->class)) {
            return true;
        }

        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return false;
        }

        return $node->name instanceof Identifier
            && in_array(strtolower($node->name->toString()), self::SQL_METHODS, true);
    }

    public static function isFacade(Name $class): bool
    {
        return in_array(strtolower($class->getLast()), self::FACADES, true);
    }

    /**
     * Строки-константы аргументов вызова (литералы, конкатенации литералов, константы).
     *
     * @return list<string>
     */
    public static function constantStringArguments(CallLike $node, Scope $scope): array
    {
        $strings = [];

        foreach ($node->getArgs() as $arg) {
            foreach ($scope->getType($arg->value)->getConstantStrings() as $constantString) {
                $strings[] = $constantString->getValue();
            }
        }

        return $strings;
    }

    /**
     * SQL, разбитый на отдельные statement'ы — чтобы `UPDATE t SET x=1` не читался как `SET`.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $statements = [];

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
