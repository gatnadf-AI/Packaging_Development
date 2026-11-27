<?php

declare(strict_types=1);

/*
 * English fallback for the FSD §2.6 catalogue.
 *
 * FSD-106 makes Indonesian the interface language and the signed wording; this
 * file exists because FSD-106 also lets a person choose English in SCR-1.3, and
 * a chosen locale that silently shows Indonesian sentences would be a lie about
 * what the preference does. The Indonesian file is the one a reviewer signs off
 * against §2.6; nothing here changes what a screen shows by default, and no
 * English row is used to answer a "what does the user read" question.
 */

return [
    'MSG-001' => 'The email or password does not match.',
    'MSG-004' => 'Your session ends in 5 minutes. Extend it?',
    'MSG-005' => 'Your session has timed out. Please sign in again.',
    'MSG-010' => 'You do not have access to this part of the system.',
    'MSG-011' => 'This document is not yours.',
    'MSG-020' => 'This record was just changed by someone else. Reload to see the latest version before saving.',
    'MSG-030' => 'Fields marked * are required.',
    'MSG-031' => 'Wrong format: {field} {rule}.',
    'MSG-040' => 'You cannot approve a document you created yourself.',
    'MSG-041' => 'A reason for the rejection is required.',
    'MSG-042' => 'The document is approved and locked. Create a new version to change it.',
    'MSG-050' => 'T{level} has not passed. {missing} must be finished and passed first.',
    'MSG-051' => 'No quotation is approved for this packaging yet. The material approval form cannot be created.',
    'MSG-052' => 'The material approval form is not approved yet. The standard cannot be created.',
    'MSG-053' => 'There are unfinished stages on this path. Scale up cannot be recorded yet.',
    'MSG-054' => 'The concept has not been approved by BOD. Packaging stages cannot start.',
    'MSG-060' => 'The file is larger than 20 MB or its type is not accepted.',
    'MSG-061' => 'Row {n} could not be imported: {alasan}. {k} of {t} rows succeeded.',
    'MSG-070' => 'The category is still used by {n} packaging items. Deactivate it, do not delete it.',
    'MSG-080' => 'The document is being prepared for printing.',
];
