<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The verbs an audit row can carry.
 *
 * §3.3.5 writes this column as an ENUM and §3.1 says status values are a
 * VARCHAR plus a PHP enum class instead; this follows §3.1, which is the
 * convention, and keeps the set closed where it can be checked by a test rather
 * than by the database. A writer that needs a verb not listed here has to add
 * the case, which is the point: the list of audited actions becomes reviewable
 * in one file (FSD-907).
 */
enum AuditAction: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRolesAssigned = 'user.roles_assigned';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';
    case UserResetLinkSent = 'user.reset_link_sent';

    case DepartmentCreated = 'department.created';
    case DepartmentUpdated = 'department.updated';
    case DepartmentDeactivated = 'department.deactivated';

    // FSD-401/402: the noun is short because target_type already names the table.
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeactivated = 'category.deactivated';
    case CategoryDeleted = 'category.deleted';

    case ItemCreated = 'item.created';
    case ItemUpdated = 'item.updated';
    case ItemDeactivated = 'item.deactivated';

    // FSD-409/812: a product is retired rather than removed, and the three
    // descriptive columns are what an edit usually changes.
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeactivated = 'product.deactivated';

    // FSD-404/406: a specification's trail is read against the version the
    // reader was looking at, so "revised" names the document that was superseded
    // and its payload points at the version that replaced it.
    case SpecCreated = 'spec.created';
    case SpecUpdated = 'spec.updated';
    case SpecRevised = 'spec.revised';

    case MatrixCellChanged = 'matrix.cell_changed';

    case SessionRevoked = 'session.revoked';
    case SessionTerminated = 'session.terminated';
}
