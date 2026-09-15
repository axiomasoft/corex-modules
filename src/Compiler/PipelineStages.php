<?php

declare(strict_types=1);

namespace CoreX\Modules\Compiler;

use CoreX\Modules\Extend\Api;
use CoreX\Modules\Extend\Entity;
use CoreX\Modules\Extend\EntityField;
use CoreX\Modules\Extend\Filament;
use CoreX\Modules\Extend\Menu;
use CoreX\Modules\Extend\Permissions;
use CoreX\Modules\Extend\Routes;
use CoreX\Modules\Extend\Workflow;

/**
 * The single place the closed 8-extender pipeline order is declared —
 * previously duplicated implicitly across {@see RegistryCompiler}
 * method-call order and {@see CompilationValidator}'s membership check,
 * with nothing enforcing they agreed. {@see RegistryCompiler::compile()}
 * drives its stage loop off this constant and {@see CompilationValidator}
 * reads the same array for closed-set membership — reordering the pipeline
 * means editing this one array, and doing so is directly observable by a
 * compile-time test (RegistryCompilerTest "pipeline stage order").
 *
 * @internal spec: B-10 §4.2/§5.2, AC-18/AC-19, A85
 */
final class PipelineStages
{
    /** @var list<class-string> */
    public const ORDER = [
        Entity::class,
        EntityField::class,
        Permissions::class,
        Api::class,
        Filament::class,
        Menu::class,
        Routes::class,
        Workflow::class,
    ];
}
