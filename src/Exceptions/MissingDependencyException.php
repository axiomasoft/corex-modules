<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

final class MissingDependencyException extends RuntimeException
{
    public function __construct(string $module, string $missingDependency)
    {
        parent::__construct(sprintf(
            'Module [%s] requires [%s], which is not enabled. Pass cascade: true to auto-enable it.',
            $module,
            $missingDependency,
        ));
    }
}
