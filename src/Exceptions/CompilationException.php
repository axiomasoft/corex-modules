<?php

declare(strict_types=1);

namespace CoreX\Modules\Exceptions;

use RuntimeException;

/**
 * Compilation validation failure: always a deploy failure, never a warning.
 *
 * @internal spec: B-10 §7.4 п.4
 */
final class CompilationException extends RuntimeException
{
    public static function duplicateTablePrefix(string $prefix, string $firstModule, string $secondModule): self
    {
        return new self(sprintf(
            'Table prefix [%s] is claimed by both [%s] and [%s] — tablePrefix must be unique across the deployment (D9).',
            $prefix,
            $firstModule,
            $secondModule,
        ));
    }

    public static function unmetRequirement(string $composerName, string $missingDependency): self
    {
        return new self(sprintf(
            'Module [%s] requires [%s], which is not installed in this deployment (requires must mirror composer, B-10 AC-2).',
            $composerName,
            $missingDependency,
        ));
    }

    public static function unknownExtender(string $composerName, string $class): self
    {
        return new self(sprintf(
            'Module [%s] declares extender [%s], which is outside the closed set of 8 CoreX\\Modules\\Extend\\* classes. '
            .'New extenders require an RFC in corex/modules (docs/rfcs/NNNN-*.md, B-10 §4.3).',
            $composerName,
            $class,
        ));
    }

    public static function unknownEntityHandle(string $composerName, string $entityHandle): self
    {
        return new self(sprintf(
            'Module [%s] declares an EntityField extender targeting entity handle [%s], which no compiled manifest registers.',
            $composerName,
            $entityHandle,
        ));
    }

    public static function tablePrefixMismatch(string $composerName, string $table, string $expectedPrefix): self
    {
        return new self(sprintf(
            'Module [%s] declares Entity table [%s], which does not start with its own table prefix [%s_] (D9).',
            $composerName,
            $table,
            $expectedPrefix,
        ));
    }

    public static function missingTablePrefix(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] manifest is missing tablePrefix() — mandatory as of D37, no auto-derivation from the '
            .'composer name (that was the D9 hole: a third-party module could legally land on a reserved prefix).',
            $composerName,
        ));
    }

    /**
     * Distinct from {@see missingTablePrefix} on purpose: that one is the
     * VALIDATION-stage rejection (validateRequiredFields); this one fires from
     * RegistryCompiler's defensive `?? throw` guards, which are unreachable once
     * validation has run. Reaching it means validateRequiredFields() was skipped
     * — a broken invariant, not a bad manifest — so the message says so loudly
     * instead of masquerading as the ordinary "missing tablePrefix()" rejection.
     * The two carrying different text is what lets a test pin the validation
     * layer specifically (a mutant that removes the validation check falls
     * through to this distinct throw and the validation-message assert reddens).
     *
     * @internal spec: P3.7
     */
    public static function tablePrefixInvariantBypassed(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] reached compile with a null tablePrefix — validateRequiredFields() bypassed. '
            .'tablePrefix is mandatory (D37) and validation must reject a prefix-less manifest before the '
            .'pipeline runs; hitting this defensive guard means that invariant was broken.',
            $composerName,
        ));
    }

    public static function missingTitle(string $composerName): self
    {
        return new self(sprintf(
            'Module [%s] manifest is missing title() — required at compile time, not just rejected as empty by '
            .'the setter (a manifest that never calls title() at all was shipping title: null into the registry).',
            $composerName,
        ));
    }

    public static function duplicateEntityHandle(string $handle, string $firstModule, string $secondModule): self
    {
        return new self(sprintf(
            'Entity handle [%s] is declared by both [%s] and [%s] — the first module\'s EntityDefinition would be '
            .'silently overwritten. Entity handles must be unique across the compiled deployment.',
            $handle,
            $firstModule,
            $secondModule,
        ));
    }
}
