<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use CoreX\Modules\CorexModule;
use RuntimeException;

final class InvalidManifestException extends RuntimeException
{
    public static function invalidComposerName(string $composerName): self
    {
        return new self(sprintf(
            'Module manifest composer name [%s] is invalid — expected "vendor/package".',
            $composerName,
        ));
    }

    public static function emptyTitle(string $composerName): self
    {
        return new self(sprintf('Module [%s] manifest is missing a title.', $composerName));
    }

    public static function invalidTablePrefix(string $composerName, string $prefix): self
    {
        return new self(sprintf(
            'Module [%s] table prefix [%s] is invalid — expected 2-3 lowercase letters ([a-z]{2,3}), D9.',
            $composerName,
            $prefix,
        ));
    }

    public static function notACorexModule(string $class): self
    {
        return new self(sprintf(
            'Discovered module class [%s] does not extend %s.',
            $class,
            CorexModule::class,
        ));
    }
}
