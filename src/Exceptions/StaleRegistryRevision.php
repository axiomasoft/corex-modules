<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

final class StaleRegistryRevision extends RuntimeException
{
    public static function mismatch(string $expected, string $actual): self
    {
        return new self(sprintf('Registry revision mismatch: expected [%s], current [%s].', $expected, $actual));
    }
}
