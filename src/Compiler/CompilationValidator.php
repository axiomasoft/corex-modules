<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Exceptions\CompilationException;
use CoreX\Modules\Extend\Extender;
use CoreX\Modules\Manifest;

/**
 * Compile-time checks — every failure here is a deploy failure, never a
 * warning. Called by RegistryCompiler before/while building the
 * CompiledRegistry.
 *
 * @internal spec: B-10 §5.2/§7.4 п.4
 */
final class CompilationValidator
{
    /** @param  list<Manifest>  $manifests */
    public function validateDependencyGraph(array $manifests): void
    {
        /** @var array<string, list<string>> $graph */
        $graph = [];

        foreach ($manifests as $manifest) {
            $graph[$manifest->composerName] = array_values(array_map(
                static fn ($dependency): string => $dependency->target,
                array_filter($manifest->requires, static fn ($dependency): bool => $dependency->type === 'module'),
            ));
        }

        /** @var array<string, true> $visited */
        $visited = [];
        $visit = function (string $module, array $path) use (&$visit, &$visited, $graph): void {
            if (in_array($module, $path, true)) {
                throw new CompilationException('Cyclic module dependency: '.implode(' -> ', [...$path, $module]));
            }

            if (isset($visited[$module])) {
                return;
            }

            foreach ($graph[$module] ?? [] as $dependency) {
                $visit($dependency, [...$path, $module]);
            }

            $visited[$module] = true;
        };

        foreach (array_keys($graph) as $module) {
            $visit($module, []);
        }
    }

    /**
     * @param  list<Manifest>  $manifests
     */
    public function validateRequiredFields(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            if ($manifest->title === null) {
                throw CompilationException::missingTitle($manifest->composerName);
            }

            if ($manifest->tablePrefix === null) {
                throw CompilationException::missingTablePrefix($manifest->composerName);
            }
        }
    }

    /** @param  list<Manifest>  $manifests */
    public function validateTablePrefixes(array $manifests): void
    {
        /** @var array<string, string> $ownerByPrefix */
        $ownerByPrefix = [];

        foreach ($manifests as $manifest) {
            if ($manifest->tablePrefix === null) {
                continue;
            }

            $owner = $ownerByPrefix[$manifest->tablePrefix] ?? null;

            if ($owner !== null) {
                throw CompilationException::duplicateTablePrefix($manifest->tablePrefix, $owner, $manifest->composerName);
            }

            $ownerByPrefix[$manifest->tablePrefix] = $manifest->composerName;
        }
    }

    /**
     * @param  list<Manifest>  $manifests
     * @param  list<string>  $installedPackages  composer names known installed beyond $manifests (e.g. corex/core).
     */
    public function validateRequires(array $manifests, array $installedPackages): void
    {
        $installed = [...$installedPackages, ...array_map(
            static fn (Manifest $manifest): string => $manifest->composerName,
            $manifests,
        )];

        foreach ($manifests as $manifest) {
            foreach ($manifest->requires as $dep) {
                if ($dep->type !== 'module') {
                    continue;
                }

                if (! in_array($dep->target, $installed, true)) {
                    throw CompilationException::unmetRequirement($manifest->composerName, $dep->target);
                }
            }
        }
    }

    /** @param  list<Manifest>  $manifests */
    public function validateExtenderSet(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            foreach ($manifest->extenders as $extender) {
                if (! $this->isKnownExtender($extender)) {
                    throw CompilationException::unknownExtender($manifest->composerName, $extender::class);
                }
            }
        }
    }

    public function validateEntityHandleKnown(string $declaringComposerName, string $entityHandle, bool $known): void
    {
        if (! $known) {
            throw CompilationException::unknownEntityHandle($declaringComposerName, $entityHandle);
        }
    }

    /**
     * `components()` → `#[AsComponent]` is a deliberate no-op:
     * corex/components is deferred, with no consumer yet. Kept as a named
     * hook so the check has an obvious home once components land.
     *
     * @param  list<Manifest>  $manifests
     *
     * @internal spec: B-10 §5.2, D6
     */
    public function validateComponents(array $manifests): void
    {
        // Deferred — corex/components has no consumer yet.
    }

    private function isKnownExtender(Extender $extender): bool
    {
        foreach (PipelineStages::ORDER as $class) {
            if ($extender instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
