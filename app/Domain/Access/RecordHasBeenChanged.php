<?php

declare(strict_types=1);

namespace App\Domain\Access;

use RuntimeException;

/**
 * FSD §2.5 (concurrency) — the row moved underneath the screen that is saving it.
 *
 * "Silent overwrite does not exist" is a rule about a race, so it can only be
 * enforced by the write itself: the update asks for the version the screen read
 * and, when the database answers that no row matched, the save did not happen.
 * This exception is how that reaches the browser — one handler turns it into the
 * MSG-020 response, so no screen has to remember to catch it and no screen can
 * win the race by forgetting to.
 *
 * It lives in the Access context because that is where the first records carrying
 * a version token are written (users, departments). When the workflow contexts
 * arrive in Phase 3 they throw this same class, and the wording stays in one
 * place for all 41 screens.
 */
final class RecordHasBeenChanged extends RuntimeException
{
    public function __construct(
        string $what,
        private readonly ?int $currentVersion = null,
    ) {
        parent::__construct("A stale save was refused for {$what}.");
    }

    /**
     * The version now in the table, so the screen can offer "Kirim ulang saya"
     * with something real to resend rather than the token it just lost.
     */
    public function currentVersion(): ?int
    {
        return $this->currentVersion;
    }
}
