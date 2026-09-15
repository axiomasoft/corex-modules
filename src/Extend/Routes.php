<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

/**
 * 7. Route files (plain Laravel routing), grouped per-module by the
 * activity middleware. `api()` auto-prefixes `/api/{module_short}`.
 *
 * @internal spec: B-10 §4.2
 */
final class Routes implements Extender
{
    /** @var 'api'|'web' */
    public string $kind;

    public string $file;

    /** @var list<string> */
    public array $middlewares = [];

    public ?string $routePrefix = null;

    private function __construct(string $kind, string $file)
    {
        $this->kind = $kind;
        $this->file = $file;
    }

    public static function api(string $file): self
    {
        return new self('api', $file);
    }

    public static function web(string $file): self
    {
        return new self('web', $file);
    }

    /** @param  list<string>  $mw */
    public function middleware(array $mw): self
    {
        $this->middlewares = $mw;

        return $this;
    }

    public function prefix(string $prefix): self
    {
        $this->routePrefix = $prefix;

        return $this;
    }
}
