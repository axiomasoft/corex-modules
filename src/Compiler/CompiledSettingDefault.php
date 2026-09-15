<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\SettingDefault;
use ReflectionClass;

/**
 * A manifest-declared {@see SettingDefault} paired with its owning module's
 * setting-namespace — `SettingDefaultsProvider`/`Manifest::$settings` never
 * reached the compiled registry before this. The namespace is the module's
 * `tablePrefix`: `sys_settings.namespace` is documented as "id модуля
 * ('crm')", which is exactly the short 2–3 letter code
 * {@see CoreX\Modules\Manifest::$tablePrefix} already carries, not the full
 * composer name.
 *
 * @internal spec: P1.25, A68, B-10 §3.1
 */
final readonly class CompiledSettingDefault
{
    public function __construct(
        public string $namespace,
        public SettingDefault $definition,
    ) {}

    /**
     * `SettingDefault` has a private constructor (fluent factory, see the
     * class) — reconstruction goes through reflection like
     * {@see CompiledRegistry::extenderFromArray()}, just not constrained to
     * the `Extender` interface.
     *
     * @return array{namespace: string, definition: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'definition' => get_object_vars($this->definition),
        ];
    }

    /**
     * @param  array{namespace: string, definition: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        $reflection = new ReflectionClass(SettingDefault::class);
        /** @var SettingDefault $definition */
        $definition = $reflection->newInstanceWithoutConstructor();

        foreach ($data['definition'] as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $reflection->getProperty($property)->setValue($definition, $value);
            }
        }

        return new self(namespace: $data['namespace'], definition: $definition);
    }
}
