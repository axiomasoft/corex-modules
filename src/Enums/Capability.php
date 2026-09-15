<?php

declare(strict_types=1);

namespace CoreX\Modules\Enums;

/**
 * Contract of satellite hookup: a satellite queries
 * EntityRegistry::byCapability() at boot and wires itself, without knowing
 * the owning module.
 *
 * @internal spec: B-10 §4.4
 */
enum Capability: string
{
    case Searchable = 'searchable';
    case Auditable = 'auditable';
    case CustomFields = 'custom_fields';
    case Workflowable = 'workflowable';
    case Commentable = 'commentable';
    case Documentable = 'documentable';
    case SoftDeletes = 'soft_deletes';

    /** Has `owner_id` — admits scope own/dept/dept_tree (B-11 §4 п.3, P2.9). */
    case Ownable = 'ownable';

    /** Has `workspace_id` — admits scope workspace (B-11 §4 п.3, P2.9). */
    case WorkspaceScoped = 'workspace_scoped';
}
