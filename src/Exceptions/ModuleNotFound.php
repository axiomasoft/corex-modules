<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

final class ModuleNotFound extends RuntimeException
{
    public function __construct(string $composerName)
    {
        parent::__construct(sprintf('Module [%s] is not in the compiled registry.', $composerName));
    }
}
