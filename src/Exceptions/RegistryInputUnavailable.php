<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

final class RegistryInputUnavailable extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('Compiled module registry is unavailable at [%s].', $path));
    }

    public static function corrupt(): self
    {
        return new self('Compiled module registry payload is corrupt or unreadable.');
    }
}
