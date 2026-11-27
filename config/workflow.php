<?php

declare(strict_types=1);

/*
 * Workflow engine configuration — TSD-PDS-003 §4, §2.7.
 *
 * Values a reviewer or administrator could plausibly want to change live here;
 * the rules themselves (transitions, guards, derivation) stay in code so they
 * cannot drift. Q-F2 fixes revision_escalation_rounds at 2 and it is not
 * exposed in the UI.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Approver service levels (working days, evaluated on the calendar table)
    |--------------------------------------------------------------------------
    */
    'sla' => [
        'approver_working_days' => (int) env('WORKFLOW_SLA_APPROVER_WORKING_DAYS', 3),
        'manager_copy_working_days' => (int) env('WORKFLOW_SLA_MANAGER_COPY_WORKING_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation (FSD Q-F2 / Q-F3)
    |--------------------------------------------------------------------------
    | Only steps whose action is APPROVE feed the escalation filter, and a
    | sample that bounces twice escalates instead of looping forever.
    */
    'revision_escalation_rounds' => 2,

    'escalation' => [
        'actions' => ['APPROVE'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations (FSD-507)
    |--------------------------------------------------------------------------
    | An unanswered supplier invitation auto-closes after this many working days.
    */
    'invitation_deadline_days' => (int) env('WORKFLOW_INVITATION_DEADLINE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Working time
    |--------------------------------------------------------------------------
    | ISO-8601 weekday numbers. Public holidays come from the
    | working_day_calendar table, which overrides this baseline.
    */
    'working_days' => [1, 2, 3, 4, 5],
    'timezone' => 'Asia/Jakarta',

    /*
    |--------------------------------------------------------------------------
    | Document types served by the one engine (TSD §4.4 effects registry)
    |--------------------------------------------------------------------------
    | Every type MUST declare both effect classes; tests/Feature/Workflow/
    | EffectsRegistryTest fails if a class is missing, so a new document type
    | can never "approve into silence".
    */
    'types' => [
        'fab' => [
            'chain' => 'FAB-PRIMARY',
            'payload_table' => 'form_approval_bk',
            'label' => 'Form Approval Bahan Kemas',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Fab\ApplyFabApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Fab\ApplyFabRejection::class,
            ],
        ],
        'specification' => [
            'chain' => 'STD-APPROVE',
            'payload_table' => 'specifications',
            'label' => 'Spesifikasi Bahan Kemas (SBK)',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Spec\ApplySpecApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Spec\ApplySpecRejection::class,
            ],
        ],
        'drawing' => [
            'chain' => 'DWG-REVIEW',
            'payload_table' => 'technical_drawings',
            'label' => 'Technical Drawing (review)',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Drawing\ApplyDrawingApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Drawing\ApplyDrawingRejection::class,
            ],
        ],
        'drawing_issue' => [
            'chain' => 'DWG-ISSUE',
            'payload_table' => 'technical_drawings',
            'label' => 'Technical Drawing (issue)',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Drawing\ApplyDrawingIssueApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Drawing\ApplyDrawingIssueRejection::class,
            ],
        ],
        'concept' => [
            'chain' => 'CONCEPT-BOD',
            'payload_table' => 'projects',
            'label' => 'Project Concept (BOD)',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Project\ApplyConceptApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Project\ApplyConceptRejection::class,
            ],
        ],
        'artwork' => [
            'chain' => 'ARTWORK-CIRC',
            'payload_table' => 'artwork_circulations',
            'label' => 'Artwork Circulation',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Artwork\ApplyArtworkApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Artwork\ApplyArtworkRejection::class,
            ],
        ],
        'colour_range' => [
            'chain' => 'ARTWORK-CIRC',
            'payload_table' => 'artwork_circulations',
            'label' => 'Colour Range Circulation',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Artwork\ApplyColourRangeApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Artwork\ApplyColourRangeRejection::class,
            ],
        ],
        'quotation' => [
            'chain' => 'QT-REVIEW',
            'payload_table' => 'quotations',
            'label' => 'Supplier Quotation',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Supplier\ApplyQuotationApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Supplier\ApplyQuotationRejection::class,
            ],
        ],
        'standard' => [
            'chain' => 'STD-APPROVE',
            'payload_table' => 'packaging_standards',
            'label' => 'Packaging Standard',
            'effects' => [
                'approved' => \App\Domain\Workflow\Effects\Standard\ApplyStandardApproval::class,
                'rejected' => \App\Domain\Workflow\Effects\Standard\ApplyStandardRejection::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled jobs (TSD §4.7)
    |--------------------------------------------------------------------------
    | The supplier digest flushes once each morning in WIB (Q-F4); N-11 and N-14
    | are excluded from the digest and are sent immediately.
    */
    'schedule' => [
        'supplier_digest_cron' => env('WORKFLOW_DIGEST_CRON', '0 8 * * *'),
        'escalation_cron' => env('WORKFLOW_ESCALATION_CRON', '30 8 * * 1-5'),
        'cache_integrity_cron' => env('WORKFLOW_INTEGRITY_CRON', '0 23 * * *'),
        'immediate_events' => ['N-11', 'N-14'],
    ],
];
