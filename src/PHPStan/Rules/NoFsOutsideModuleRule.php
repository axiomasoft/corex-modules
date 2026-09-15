<?php

declare(strict_types=1);

namespace CoreX\Modules\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fs-операции модуля не выходят за его директорию — запись в чужие пути
 * ломает изоляцию и переживает деплой контейнера.
 *
 * Статически виден только путь-константа: абсолютный путь или обход `..` — ошибка;
 * вычисляемый путь (`storage_path(...)`, конкатенация с переменной) правило не
 * трогает, чтобы не давать ложных срабатываний. Скоуп — неймспейсы модулей из
 * `parameters.corexModules.moduleNamespaces` (ядро и приложение не затрагиваются).
 *
 * @implements Rule<FuncCall>
 *
 * @internal spec: B-10 §7.3
 */
final class NoFsOutsideModuleRule implements Rule
{
    private const FS_FUNCTIONS = [
        'file_put_contents', 'file_get_contents', 'fopen', 'unlink', 'mkdir', 'rmdir',
        'rename', 'copy', 'touch', 'scandir', 'opendir', 'file',
    ];

    /**
     * @param  list<string>  $moduleNamespaces
     */
    public function __construct(private readonly array $moduleNamespaces) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param  FuncCall  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name || ! $this->insideModule($scope)) {
            return [];
        }

        if (! in_array(strtolower($node->name->toString()), self::FS_FUNCTIONS, true)) {
            return [];
        }

        $args = $node->getArgs();

        if ($args === [] || $this->anchoredToOwnFile($args[0]->value)) {
            return [];
        }

        $errors = [];

        foreach ($scope->getType($args[0]->value)->getConstantStrings() as $constantString) {
            $path = $constantString->getValue();

            if (! str_starts_with($path, '/') && ! str_contains($path, '..')) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Filesystem access outside the module directory is forbidden (B-10 §7.3 enforcement): "%s".',
                $path,
            ))
                ->identifier('corexModules.fsOutsideModule')
                ->build();
        }

        return $errors;
    }

    /**
     * `__DIR__`/`__FILE__`-относительный путь — это путь внутри самого модуля;
     * PHPStan разворачивает его в абсолютную строку, и без этой проверки штатный
     * `file_get_contents(__DIR__.'/stub.php')` давал бы ложное срабатывание.
     */
    private function anchoredToOwnFile(Expr $expr): bool
    {
        if ($expr instanceof MagicConst\Dir || $expr instanceof MagicConst\File) {
            return true;
        }

        if ($expr instanceof Concat) {
            return $this->anchoredToOwnFile($expr->left) || $this->anchoredToOwnFile($expr->right);
        }

        return false;
    }

    private function insideModule(Scope $scope): bool
    {
        $namespace = $scope->getClassReflection()?->getName() ?? $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        foreach ($this->moduleNamespaces as $moduleNamespace) {
            if (str_starts_with($namespace, rtrim($moduleNamespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }
}
