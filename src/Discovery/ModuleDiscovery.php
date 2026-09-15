<?php

declare(strict_types=1);

namespace CoreX\Modules\Discovery;

use CoreX\Modules\CorexModule;
use CoreX\Modules\Exceptions\InvalidManifestException;
use CoreX\Modules\Manifest;

/**
 * Finds installed modules and builds their manifests. Default source is
 * `vendor/composer/installed.json` (`type: corex-module`, class from
 * `extra.corex.module`); an injected fallback list lets tests and
 * testbench avoid depending on real installed.json contents.
 *
 * @internal spec: B-10 §3.2/§5.2
 */
final class ModuleDiscovery
{
    /**
     * @param  list<class-string<CorexModule>>  $fallbackModules  injectable seam for tests/testbench.
     */
    public function __construct(
        private readonly array $fallbackModules = [],
        private readonly ?string $installedJsonPath = null,
    ) {}

    /** @return list<Manifest> */
    public function discover(): array
    {
        $classes = $this->fallbackModules !== [] ? $this->fallbackModules : $this->scanInstalledJson();

        return array_map($this->manifestFor(...), $classes);
    }

    /** @param  class-string  $class */
    private function manifestFor(string $class): Manifest
    {
        if (! is_subclass_of($class, CorexModule::class)) {
            throw InvalidManifestException::notACorexModule($class);
        }

        /** @var CorexModule $module */
        $module = new $class;

        return $module->manifest();
    }

    /** @return list<class-string> */
    private function scanInstalledJson(): array
    {
        $path = $this->installedJsonPath ?? base_path('vendor/composer/installed.json');

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            return [];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $classes = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['type'] ?? null) !== 'corex-module') {
                continue;
            }

            $class = $package['extra']['corex']['module'] ?? null;

            if (is_string($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
