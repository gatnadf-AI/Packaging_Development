<?php

declare(strict_types=1);

/*
 * Numbering — TSD-PDS-003 §4.5 (TSD-430..432).
 *
 * One allocator serves every pattern. Tokens:
 *   {Y}    four-digit period year
 *   {YY}   two-digit period year
 *   {N:n}  zero-padded sequence for the scope+period
 *   {A}    assignment code (used by inspection numbers)
 *
 * Allocated values are never reused, even when the document is cancelled
 * (BR-13), and there is no rename path.
 */

return [

    'prefix' => env('NUMBERING_PREFIX', 'PPM'),

    'patterns' => [
        'project' => 'NPD-{Y}-{N:3}',
        'fab' => 'FAB-{Y}-{N:3}',
        'sbk' => 'QC/BP/{N:3}/{YY}',
        'quotation' => 'QT-{Y}-{N:4}',
        'inspection' => 'INS-{A}-{L}',
        'drawing' => 'DWG-{N:4}',
        'assignment' => '{P}/{B}{N:2}',
        'standard' => 'STD-{N:3}/{YY}',
        'artwork' => 'AWK-{Y}-{N:3}',
        'scale_up' => 'SCL-{Y}-{N:3}',
    ],

    // Sequence rows are scoped by (scope, period); {YY}/{Y} patterns reset yearly.
    'periods' => [
        'project' => 'year',
        'fab' => 'year',
        'sbk' => 'year',
        'quotation' => 'year',
        'drawing' => 'none',
        'standard' => 'year',
        'artwork' => 'year',
        'scale_up' => 'year',
    ],
];
