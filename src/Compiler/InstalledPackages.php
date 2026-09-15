<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Discovery\ModuleDiscovery;

/**
 * The real composer dependency graph {@see RegistryCompiler::compile()}
 * checks `requires(Dep::module(...))` against by default. Reads the
 * standard `vendor/composer/installed.json` (Composer's own installed-set
 * manifest — no custom format, same file {@see ModuleDiscovery} scans for
 * corex-module packages). A missing/unreadable file resolves to an empty
 * list rather than failing — a deployment with zero third-party composer
 * packages is legal, and `requires` between manifests being compiled
 * together is still checked against each other regardless of this list.
 *
 * @internal spec: AC-2, B-10 §7.4 п.4
 */
final class InstalledPackages
{
    /** @return list<string> */
    public static function discover(?string $installedJsonPath = null): array
    {
        $path = $installedJsonPath ?? base_path('vendor/composer/installed.json');

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            return [];
        }

        /** @var list<mixed> $packages */
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $names = [];

        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }
}
