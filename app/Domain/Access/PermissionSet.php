<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * The resolved view of what one person may do, keyed by feature (FSD-614).
 *
 * Immutable once built. A screen or a policy asks it a question; neither is
 * allowed to add its own role test, because FSD §6 is the single source of
 * menu, button and API authorisation.
 */
final class PermissionSet
{
    /**
     * @param  array<string, PermissionLevel>  $levels
     * @param  list<string>  $roleSlugs
     */
    public function __construct(
        private readonly array $levels,
        private readonly array $roleSlugs,
        private readonly int $matrixVersion,
    ) {}

    /**
     * @param  array<string, PermissionLevel>  $levels
     * @param  list<string>  $roleSlugs
     */
    public static function fromArray(array $levels, array $roleSlugs, int $matrixVersion): self
    {
        return new self($levels, $roleSlugs, $matrixVersion);
    }

    public static function empty(): self
    {
        return new self([], [], 0);
    }

    public function level(string $feature): PermissionLevel
    {
        return $this->levels[$feature] ?? PermissionLevel::None;
    }

    public function canView(string $feature): bool
    {
        return $this->level($feature)->canView();
    }

    public function canAct(string $feature): bool
    {
        return $this->level($feature)->canAct();
    }

    /**
     * @return list<string>
     */
    public function viewableFeatures(): array
    {
        return array_values(array_keys(array_filter(
            $this->levels,
            static fn (PermissionLevel $l) => $l->canView()
        )));
    }

    /**
     * @return list<string>
     */
    public function roleSlugs(): array
    {
        return $this->roleSlugs;
    }

    public function matrixVersion(): int
    {
        return $this->matrixVersion;
    }

    public function hasRole(string $slug): bool
    {
        return in_array($slug, $this->roleSlugs, true);
    }
}
