<?php

declare(strict_types=1);

/*
 * Upload policy — TSD-PDS-003 §2.7, §3.5, FSD §2.5.
 *
 * php.ini on this host allows 2G; the application enforces far less, in
 * validation rather than by relying on the web server. Files are stored on the
 * private disk under a hashed name; the original name is kept only for display
 * and printing.
 */

return [

    'disk' => env('UPLOAD_DISK', 'local'),

    // Hard ceiling for any single field; per-field limits may be lower.
    'max_mb' => (int) env('UPLOAD_MAX_MB', 20),

    'allowed_mimes' => [
        'evidence' => ['pdf', 'jpg', 'jpeg', 'png'],
        'drawing' => ['pdf', 'dwg', 'dxf'],
        'specification_import' => ['xlsx', 'xls', 'csv'],
        'artwork' => ['pdf', 'ai', 'eps', 'jpg', 'jpeg', 'png', 'tif', 'tiff'],
        'report' => ['pdf', 'xlsx', 'csv'],
    ],

    // Scanned/rejected at the boundary: no executables, no macros.
    'forbidden_mimes' => [
        'application/x-msdownload',
        'application/x-ms-dos-executable',
        'application/vnd.ms-excel.addin.macroenabled.12',
        'application/vnd.ms-word.document.macroenabled.12',
        'text/x-php',
        'application/x-php',
    ],

    'retention_days' => (int) env('UPLOAD_RETENTION_DAYS', 3650),
];
