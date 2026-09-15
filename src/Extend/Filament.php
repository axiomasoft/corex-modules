<?php

declare(strict_types=1);

namespace CoreX\Modules\Extend;

/**
 * 4. Filament resources/pages/widgets, or a tab bolted onto ANOTHER
 * module's entity resource — bridges into corex/filament's Plugin Registry.
 *
 * @internal spec: B-10 §4.2
 */
final class Filament implements Extender
{
    /** @var 'resources'|'pages'|'widgets'|'tab' */
    public string $kind;

    public string $panel;

    /** @var list<class-string> */
    public array $classes = [];

    public ?string $entityHandle = null;

    private function __construct()
    {
        //
    }

    /** @param  list<class-string>  $resources */
    public static function resources(array $resources, string $panel = 'app'): self
    {
        $filament = new self;
        $filament->kind = 'resources';
        $filament->classes = $resources;
        $filament->panel = $panel;

        return $filament;
    }

    /** @param  list<class-string>  $pages */
    public static function pages(array $pages, string $panel = 'app'): self
    {
        $filament = new self;
        $filament->kind = 'pages';
        $filament->classes = $pages;
        $filament->panel = $panel;

        return $filament;
    }

    /** @param  list<class-string>  $widgets */
    public static function widgets(array $widgets, string $panel = 'app'): self
    {
        $filament = new self;
        $filament->kind = 'widgets';
        $filament->classes = $widgets;
        $filament->panel = $panel;

        return $filament;
    }

    /** @param  class-string  $tabClass */
    public static function tab(string $entityHandle, string $tabClass, string $panel = 'app'): self
    {
        $filament = new self;
        $filament->kind = 'tab';
        $filament->entityHandle = $entityHandle;
        $filament->classes = [$tabClass];
        $filament->panel = $panel;

        return $filament;
    }
}
