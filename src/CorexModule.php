<?php

declare(strict_types=1);

namespace CoreX\Modules;

/**
 * Base class of a CoreX module: a concrete subclass describes itself
 * declaratively via manifest(), discovered from `installed.json`
 * (extra.corex.module) — no imperative registration.
 *
 * @internal spec: B-10 §3.2
 */
abstract class CorexModule
{
    abstract public function manifest(): Manifest;
}
