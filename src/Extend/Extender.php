<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

/**
 * Marker interface for the closed set of 8 extenders. A third-party class
 * implementing this outside the set is rejected by RegistryCompiler with a
 * reference to the RFC process.
 *
 * @internal spec: B-10 §4.2, §4.3
 */
interface Extender {}
