<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

final class InvalidRegistryPoint extends RuntimeException
{
    public static function unknown(string $name): self
    {
        return new self(sprintf('Unknown registry extension point [%s].', $name));
    }
}
