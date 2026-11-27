<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access;

use App\Domain\Access\PermissionLevel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TSD-PDS-003 §13 (Unit) — the three states of an FSD §6 cell.
 *
 * The sheet is the source, so fromSheet() is tested against exactly the
 * characters the approved document uses, including the em dash. A silently
 * accepted typo in a hand-typed matrix would flip a door open.
 */
final class PermissionLevelTest extends TestCase
{
    #[DataProvider('sheetCells')]
    public function testSheetCharactersMapToTheThreeStates(string $cell, PermissionLevel $expected): void
    {
        self::assertSame($expected, PermissionLevel::fromSheet($cell));
    }

    /**
     * @return array<string, array{0: string, 1: PermissionLevel}>
     */
    public static function sheetCells(): array
    {
        return [
            'F means read and act' => ['F', PermissionLevel::Full],
            'R means read only' => ['R', PermissionLevel::Read],
            'em dash means no access' => ['—', PermissionLevel::None],
            'hyphen is tolerated as no access' => ['-', PermissionLevel::None],
            'blank is no access' => ['', PermissionLevel::None],
            'padded cell is trimmed' => [' F ', PermissionLevel::Full],
        ];
    }

    public function testAnUnrecognisedCellIsRefusedRatherThanTreatedAsNone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionLevel::fromSheet('X');
    }

    public function testViewAndActFollowTheLevelAndNothingElse(): void
    {
        self::assertTrue(PermissionLevel::Full->canAct());
        self::assertTrue(PermissionLevel::Full->canView());

        self::assertFalse(PermissionLevel::Read->canAct());
        self::assertTrue(PermissionLevel::Read->canView());

        self::assertFalse(PermissionLevel::None->canAct());
        self::assertFalse(PermissionLevel::None->canView());
    }

    public function testTheEditorCyclesFullReadNoneFull(): void
    {
        // FSD-204: one click advances the cell through the three states.
        self::assertSame(
            [PermissionLevel::Read, PermissionLevel::None, PermissionLevel::Full],
            [
                PermissionLevel::Full->next(),
                PermissionLevel::Read->next(),
                PermissionLevel::None->next(),
            ]
        );
    }

    public function testSheetRoundTripsTheApprovedCharacters(): void
    {
        foreach ([PermissionLevel::Full, PermissionLevel::Read] as $level) {
            self::assertSame($level, PermissionLevel::fromSheet($level->sheet()));
        }

        // None prints as an em dash, and reading that back yields None again.
        self::assertSame(PermissionLevel::None, PermissionLevel::fromSheet(PermissionLevel::None->sheet()));
    }
}
