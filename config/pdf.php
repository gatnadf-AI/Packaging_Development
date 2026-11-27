<?php

declare(strict_types=1);

/*
 * Document generation — TSD-PDS-003 §8, ADR-11.
 *
 * Dompdf is primary (pure PHP, deterministic, no external binary on this host).
 * Chrome headless is the selectable fallback for complex SBK layouts; it is
 * disabled unless an absolute --print-to-pdf capable binary is configured,
 * because a relative path silently fails on Windows.
 */

return [

    'engine' => env('PDF_ENGINE', 'dompdf'),

    'paper' => [
        'orientation' => 'portrait',
        'size' => 'A4',
        'margin_mm' => ['top' => 18, 'right' => 15, 'bottom' => 18, 'left' => 15],
    ],

    'fonts' => [
        'family' => 'DejaVu Sans',
        'size_pt' => 9,
    ],

    'chrome' => [
        'binary' => env('PDF_CHROME_PATH'),
        'timeout_seconds' => (int) env('PDF_CHROME_TIMEOUT', 90),
        'flags' => [
            '--headless',
            '--disable-gpu',
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
        ],
    ],

    // Generated PDFs live on the private disk; a signed URL is never persisted.
    'disk' => env('PDF_DISK', 'local'),
    'archive_dir' => 'pdf',
    'keep_days' => (int) env('PDF_KEEP_DAYS', 365),

    'signature_block' => [
        // OQ-01: four signature blocks on the printed FAB form.
        'columns' => 4,
        'width_percent' => 25,
        'line_count' => 3,
    ],
];
