<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * The two kinds of read the access log distinguishes (TSD §3.3.5).
 *
 * READ_DENIED is the attempted route that the matrix refused (§5.3, FSD-907's
 * failed permission attempts, and FSD-601's supplier isolation breach, which
 * §601 requires to leave a security entry). READ_COMMERCIAL is the read of a
 * price or quotation that a reviewer may later want explained; it exists in the
 * catalogue so the sourcing module records it rather than inventing a verb when
 * it gets there.
 */
enum AccessLogKind: string
{
    case ReadDenied = 'READ_DENIED';
    case ReadCommercial = 'READ_COMMERCIAL';
}
