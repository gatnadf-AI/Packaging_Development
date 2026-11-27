<?php

declare(strict_types=1);

/*
 * Runtime permission matrix — TSD-PDS-003 §5, FSD §6 (the "Hak Akses" sheet of
 * the source workbook is the only part of that workbook the flowchart allowed).
 *
 * The matrix is DATA (features + feature_grants tables), not code. What lives
 * here are the invariants: deny by default, one invalidation mechanism, and the
 * identity set the matrix×route test is run against.
 */

return [

    // FSD-204: a permission change must be visible immediately, so the
    // resolver cache lives until the matrix version bumps — not for a TTL.
    'cache_ttl_seconds' => 0,

    // Absence of a feature_grants row is NONE, never an inferred FULL.
    'default_level' => 'NONE',

    'levels' => ['FULL', 'READ', 'NONE'],

    /*
    |--------------------------------------------------------------------------
    | The fourteen identities the matrix × route test iterates over (AC-C9)
    |--------------------------------------------------------------------------
    | Twelve business roles from the FSD §6 grid plus the two system
    | identities of FSD §6.1. Slugs are authoritative and are shared with
    | database/seeders/data/permission-matrix.php; labels are display only.
    | Tests assert every route's verdict against feature_grants, never against
    | a hard-coded role list in a controller (FSD-614).
    */
    'identities' => [
        'mkt' => 'Marketing',
        'rnd_staff' => 'R&D Staff',
        'rnd_manager' => 'R&D Manager',
        'pd_staff' => 'Packaging Development Staff',
        'pd_manager' => 'Packaging Development Manager',
        'qc_supervisor' => 'QC Supervisor',
        'qc_manager' => 'QC Manager',
        'scm_staff' => 'SCM Staff',
        'scm_manager' => 'SCM Manager',
        'qa_supervisor' => 'QA Supervisor',
        'qa_manager' => 'QA Manager',
        'bod' => 'Board of Directors',
        'admin' => 'Administrator',
        'supplier' => 'Supplier Portal',
    ],

    // Q-F6: one person may hold two roles; the union is granted, but the
    // segregation resolver still allows only one step per chain for that person.
    'multi_role' => [
        'union' => true,
        'segregate_within_chain' => true,
    ],
];
