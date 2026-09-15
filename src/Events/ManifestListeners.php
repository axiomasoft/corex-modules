<?php

declare(strict_types=1);

namespace CoreX\Modules\Events;

use CoreX\Exceptions\CentralContextException;
use CoreX\Modules\Compiler\CompiledRegistry;
use CoreX\Modules\Contracts\ModuleRegistry;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use Illuminate\Events\Dispatcher;

/** @internal Registers declarations once and evaluates module activity at dispatch time. */
final class ManifestListeners
{
    /** @var array<string, true> */
    private array $registered = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ModuleRegistry $modules,
        private readonly TenantContextResolver $context,
    ) {}

    public function register(CompiledRegistry $registry): void
    {
        foreach ($registry->listeners as $module => $events) {
            foreach ($events as $event => $listeners) {
                foreach (array_unique($listeners) as $listener) {
                    $key = $module.'|'.$event.'|'.$listener;

                    if (isset($this->registered[$key])) {
                        continue;
                    }
                    $this->registered[$key] = true;
                    $invoke = $this->events->makeListener($listener);
                    $this->events->listen($event, function (...$payload) use ($module, $event, $listener, $invoke): mixed {
                        try {
                            $context = $this->context->current();
                        } catch (CentralContextException) {
                            return null;
                        }

                        if (! $this->modules->isActive($module, $context)
                            || ! in_array($listener, $this->modules->compiled()->listeners[$module][$event] ?? [], true)) {
                            return null;
                        }

                        return $invoke($event, $payload);
                    });
                }
            }
        }
    }
}
