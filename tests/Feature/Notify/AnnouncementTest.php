<?php

declare(strict_types=1);

namespace Tests\Feature\Notify;

use App\Domain\Access\ChangeRefused;
use App\Domain\Notify\AnnouncementService;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * FSD-208 (SCR-2.4), the writing half.
 *
 * An announcement is the one record an administrator types that is read by
 * everybody, so the rules that matter are the ones that decide who is addressed
 * and when the words are up. The audience is stored as written and checked
 * against the lists that actually exist: an audience naming a role that has been
 * renamed would silently reach nobody, and "published, but to whom it may concern
 * at a department that is gone" is the kind of sentence that only the writer can
 * hear — the reader cannot tell the difference between a typo and a quiet
 * workforce.
 *
 * Stopping rather than deleting an announcement that has already been live is
 * the same instinct as G-13 for master data, and it is a decision on the record
 * (see the class comment in AnnouncementService): a banner somebody dismissed
 * leaves a row, and deleting the announcement those rows point at either orphans
 * them or quietly re-shows a message to the person who already read it.
 */
final class AnnouncementTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private CarbonImmutable $now;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->now = CarbonImmutable::parse('2026-11-24 10:00:00');

        /** @var User $actor */
        $actor = User::factory()->role('admin')->create(['name' => 'Penjaga Sistem']);

        $this->actor = $actor;
    }

    public function testAnAnnouncementIsStoredWithItsAudienceAndItsWindow(): void
    {
        $announcement = $this->service()->create([
            'title' => 'Ruang QC ditutup untuk kalibrasi',
            'body' => "Pengujian T0 digeser ke hari Jumat.\nTidak perlu menjawab surel ini.",
            'audience' => ['type' => 'roles', 'roles' => ['qc_supervisor', 'qc_manager']],
            'publish_at' => $this->now,
            'expire_at' => $this->now->addDays(3),
        ], $this->actor, $this->now);

        $this->assertSame('Ruang QC ditutup untuk kalibrasi', $announcement->title);
        $this->assertSame(['type' => 'roles', 'roles' => ['qc_supervisor', 'qc_manager']], $announcement->audience);
        $this->assertSame($this->now->toDateTimeString(), $announcement->publish_at->toDateTimeString());
        $this->assertSame(
            $this->now->addDays(3)->toDateTimeString(),
            $announcement->expire_at->toDateTimeString()
        );
        $this->assertSame($this->actor->getKey(), $announcement->created_by);
    }

    public function testAPublishTimeInThePastMeansItIsUpNowRatherThanSomeday(): void
    {
        // FSD-208's own note: "Publish in the past = immediate". The requirement
        // is about a thing the administrator does on purpose — they type the time
        // the notice should have gone up, and the system must not schedule it for
        // a moment that has already been and gone.
        $announcement = $this->service()->create([
            'title' => 'Perubahan shift laboratorium',
            'body' => 'Berlaku mulai hari ini.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->subHours(4),
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->assertTrue($announcement->isLiveAt($this->now));
    }

    public function testAnAnnouncementWithNoEndStaysUpUntilItIsStopped(): void
    {
        $announcement = $this->service()->create([
            'title' => 'Kebijakan baru untuk label pangan',
            'body' => 'Baca di SOP terbaru.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->assertNull($announcement->expire_at);
        $this->assertTrue($announcement->isLiveAt($this->now->addYear()));
    }

    public function testAnEndingTimeBeforeThePublishingTimeIsRefused(): void
    {
        $this->expectException(ChangeRefused::class);
        $this->expectExceptionMessage('setelah');

        $this->service()->create([
            'title' => 'Salah ketik',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->addDay(),
            'expire_at' => $this->now,
        ], $this->actor, $this->now);
    }

    public function testAnAnnouncementThatHasAlreadyEndedIsNotWorthSaving(): void
    {
        // Both sides of the window are in the past: the row would be born over.
        // The screen has nothing to show for it and the administrator believes
        // they have published something.
        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => 'Pengumuman yang lahir-lahir sudah selesai',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->subDays(9),
            'expire_at' => $this->now->subDays(2),
        ], $this->actor, $this->now);
    }

    public function testAnAudienceNamingACharacterThatIsNotOnTheGridIsRefused(): void
    {
        $this->expectException(ChangeRefused::class);
        $this->expectExceptionMessage('quality_lead');

        $this->service()->create([
            'title' => 'Salah nama peran',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'roles', 'roles' => ['quality_lead']],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testAnAudienceNamingADepartmentThatIsNotOnTheListIsRefused(): void
    {
        $missing = 1 + (int) DB::table('departments')->max('id');

        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => 'Salah departemen',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'departments', 'departments' => [$missing]],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testAnEmptyAudienceListIsRefusedRatherThanLeftToReachEverybody(): void
    {
        // The difference matters: an audience of no roles is not "all", and a
        // writer who meant "all" would otherwise see a notice nobody can read.
        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => 'Daftar kosong',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'roles', 'roles' => []],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testAnAudienceShapeTheScreenNeverSendsIsRefused(): void
    {
        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => 'Bentuk audiens asing',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'branches'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testATitleThatIsOnlySpacesIsRefused(): void
    {
        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => '   ',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testABodyThatIsOnlySpacesIsRefused(): void
    {
        // The same rule as the title, and it belongs here rather than in the
        // form request for the same reason: a run of spaces is a non-empty
        // string, so the framework's "required" accepts it and the banner that
        // reaches the staff is a heading with nothing under it.
        $this->expectException(ChangeRefused::class);

        $this->service()->create([
            'title' => 'Judul',
            'body' => " \t ",
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);
    }

    public function testStoppingAnnouncementTakesItDownWithoutErasingIt(): void
    {
        $announcement = $this->service()->create([
            'title' => 'Cut bersama',
            'body' => 'Pabrik libur satu hari.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->subHour(),
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->service()->stop($announcement, $this->now);

        $fresh = Announcement::query()->findOrFail($announcement->getKey());

        $this->assertNotNull($fresh->expire_at);
        $this->assertSame($this->now->toDateTimeString(), $fresh->expire_at->toDateTimeString());
        $this->assertFalse($fresh->isLiveAt($this->now->addMinute()));
    }

    public function testStoppingAnAnnouncementThatFinishedOnItsOwnIsRefusedBecauseItWouldReopenIt(): void
    {
        // The service refuses windows that have already closed, so this row is
        // written as an edited date or a scheduler would leave it: live a
        // fortnight ago, over yesterday. Stopping it would set expire_at to now,
        // which is later, and the words would appear again over the names of the
        // people who had already dismissed them.
        $ended = Announcement::query()->create([
            'title' => 'Sudah selesai sendiri',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->subDays(14),
            'expire_at' => $this->now->subDay(),
            'created_by' => $this->actor->getKey(),
        ]);

        $this->expectException(ChangeRefused::class);

        $this->service()->stop($ended, $this->now);
    }

    public function testAnAnnouncementThatHasBeenUpIsNotDeletable(): void
    {
        $announcement = $this->service()->create([
            'title' => 'Sudah pernah tayang',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->subDay(),
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->expectException(ChangeRefused::class);

        $this->service()->delete($announcement, $this->now);
    }

    public function testAnAnnouncementThatHasNotGoneUpYetCanBeTakenBack(): void
    {
        $announcement = $this->service()->create([
            'title' => 'Belum tayang',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'departments', 'departments' => [
                Department::query()->where('name', 'Quality Control')->sole()->id,
            ]],
            'publish_at' => $this->now->addDay(),
            'expire_at' => $this->now->addDays(2),
        ], $this->actor, $this->now);

        $this->service()->delete($announcement, $this->now);

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->getKey()]);
    }

    public function testADismissalIsRememberedOncePerAccountAndAnnouncement(): void
    {
        /** @var User $reader */
        $reader = User::factory()->role('qc_supervisor')->create();

        $announcement = $this->service()->create([
            'title' => 'Pengumuman untuk semua',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->service()->dismiss($announcement, $reader, $this->now);
        $this->service()->dismiss($announcement, $reader, $this->now->addMinute());

        $this->assertSame(
            1,
            DB::table('announcement_reads')
                ->where('announcement_id', $announcement->getKey())
                ->where('user_id', $reader->getKey())
                ->count()
        );
    }

    /**
     * FSD-907 lists what the trail records, and an announcement banner is not in
     * the list: it is not a permission change, a decision, or an export. The test
     * exists so that a later editor who adds a write there can see it was checked
     * against the requirement rather than assumed.
     */
    public function testWritingAnAnnouncementLeavesNoAuditRowBecauseTheCatalogueDoesNotNameOne(): void
    {
        $this->service()->create([
            'title' => 'Tanpa jejak',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now,
            'expire_at' => null,
        ], $this->actor, $this->now);

        $this->assertSame(0, DB::table('audit_entries')->count());
    }

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }
}
