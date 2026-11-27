<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;
use App\Support\Msg;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — SCR-2.4, the announcements screen (FSD-208), and the banner it produces.
 *
 * Two halves of one requirement are tested here, and they are deliberately not
 * tested in the same file as the domain rules: FSD-208 says an administrator
 * writes a notice with an audience and a window, and that the same notice arrives
 * as a dismissible banner for the people it addresses. The first half is a
 * screen; the second half is every screen, because the banner is drawn by the
 * shell (FSD §2.3) and not by a page. What the tests below therefore check is the
 * HTTP contract on both sides — what the administrator posts, what the shell is
 * handed — while AnnouncementTest and AnnouncementVisibilityTest check the rules
 * themselves.
 *
 * Two decisions in the routing are worth naming, because both look like omissions.
 *
 * The list is opened at admin.access READ while its writes are FULL, exactly as
 * the matrix screen splits. Someone asked to look over what is going up should be
 * able to see the sheet without being handed the publish button.
 *
 * The dismissal carries no cell at all. It is the reader's own preference about a
 * banner they are already allowed to see, so gating it on admin.access would mean
 * the one population that actually sees announcements — everyone else — could not
 * switch the banner off. FSD-614's "no screen adds its own role test" is a rule
 * about who may reach a function, not a rule that a person needs permission to
 * say "I have read this".
 */
final class AnnouncementAdministrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private CarbonImmutable $now;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-24 10:00:00'));
        $this->now = CarbonImmutable::now();

        $this->administrator = $this->adminActor();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function testTheScreenListsEveryWindowWithItsAudienceItsStateAndItsAuthor(): void
    {
        $live = $this->announce('Ruang QC ditutup', ['type' => 'roles', 'roles' => ['qc_supervisor']], $this->now->subHour());
        $scheduled = $this->announce('Audit internal', ['type' => 'all'], $this->now->addDays(2));
        $ended = $this->announce('Cut bersama', ['type' => 'departments', 'departments' => $this->departmentIds('Quality Control')], $this->now->subDays(9));
        $ended->forceFill(['expire_at' => $this->now->subDays(6)])->save();

        $this->actingAs($this->administrator)
            ->get('/admin/announcements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Announcements')
                ->where('screen', 'SCR-2.4')
                ->where('announcements.total', 3)
                ->where('announcements.data.0.id', $scheduled->getKey())
                ->where('announcements.data.0.state', 'scheduled')
                ->where('announcements.data.1.id', $live->getKey())
                ->where('announcements.data.1.state', 'live')
                ->where('announcements.data.1.state_label', 'Tayang')
                ->where('announcements.data.1.audience_label', 'Peran: QC Supervisor')
                ->where('announcements.data.1.author', 'Penjaga Sistem')
                ->where('announcements.data.2.state', 'ended')
                ->where('can.write', true)
            );
    }

    public function testTheWordingOfAnAudienceNamesThePeopleRatherThanTheKeys(): void
    {
        // §2.2 keeps database identifiers off the screen, and an audience is the
        // one place on this sheet where a key would read as if it were a name:
        // "qc_supervisor" looks like a person's handle, not like a role.
        $this->announce('Kalibrasi', ['type' => 'roles', 'roles' => ['qc_supervisor', 'qc_manager']], $this->now);
        $this->announce('Semua', ['type' => 'all'], $this->now);

        // Spelled in the order the audience was written: the domain keeps the
        // list as the administrator left it, and a screen that re-sorted it would
        // be a second opinion about the same sentence. The exact comparison is
        // also the assertion that no key leaks — a label that contains
        // "qc_supervisor" cannot equal this string.
        $this->actingAs($this->administrator)
            ->get('/admin/announcements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('announcements.data.0.audience_label', 'Semua staff')
                ->where('announcements.data.1.audience_label', 'Peran: QC Supervisor, QC Manager')
            );
    }

    public function testTheScreenOffersTheAudienceListsFromTheOnesThatExist(): void
    {
        $this->actingAs($this->administrator)
            ->get('/admin/announcements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reference.roles', 14)
                ->where('reference.roles.0.value', 'admin')
                ->where('reference.roles.0.label', 'Administrator')
                ->has('reference.departments', DB::table('departments')->count())
                ->where('reference.departments.0.value', DB::table('departments')->orderBy('name')->value('id'))
            );
    }

    public function testTheScreenIsRefusedToAnAccountWhoseCellIsNone(): void
    {
        // FSD-101's other half: the menu entry is absent for this account, and
        // typing the address arrives at the same answer anyway.
        /** @var User $marketer */
        $marketer = User::factory()->role('mkt')->create();

        $this->actingAs($marketer)
            ->get('/admin/announcements')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', Msg::get('MSG-010'));
    }

    public function testAReadOnlyHolderIsShownTheSheetWithoutThePublishButton(): void
    {
        DB::table('feature_grants')->where('feature_key', 'admin.access')->update(['level' => 'READ']);

        /** @var User $reviewer */
        $reviewer = User::factory()->role('admin')->create();

        $this->actingAs($reviewer)
            ->get('/admin/announcements')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.write', false));

        $this->actingAs($reviewer)
            ->post('/admin/announcements', $this->payload())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', Msg::get('MSG-010'));

        $this->assertDatabaseCount('announcements', 0);
    }

    public function testAnAdministratorPublishesAnAnnouncementToEveryone(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/announcements', $this->payload([
                'title' => 'Perubahan shift laboratorium',
                'audience' => ['type' => 'all'],
                'publish_at' => $this->now->toDateTimeString(),
                'expire_at' => null,
            ]))
            ->assertRedirect();

        $row = Announcement::query()->sole();

        $this->assertSame('Perubahan shift laboratorium', $row->title);
        $this->assertSame(['type' => 'all'], $row->audience);
        $this->assertSame($this->administrator->getKey(), $row->created_by);
        $this->assertSame($this->now->toDateTimeString(), $row->publish_at->toDateTimeString());
    }

    public function testAnAnnouncementIsAddressedToTheRolesChosenOnTheScreen(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/announcements', $this->payload([
                'audience' => ['type' => 'roles', 'roles' => ['qa_manager', 'pd_manager']],
            ]))
            ->assertRedirect();

        $this->assertSame(
            ['type' => 'roles', 'roles' => ['qa_manager', 'pd_manager']],
            Announcement::query()->sole()->audience
        );
    }

    public function testAnAudienceChosenWithoutAnythingInItComesBackUnderItsOwnField(): void
    {
        // The screen's list boxes can both be left empty, and the answer has to
        // name the field so the person sees which half of the audience to fix
        // rather than a sentence floating above the form.
        $this->actingAs($this->administrator)
            ->post('/admin/announcements', $this->payload([
                'audience' => ['type' => 'roles', 'roles' => []],
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('audience');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function testAnEndingTimeBeforeThePublishingTimeComesBackUnderTheFinishField(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/announcements', $this->payload([
                'publish_at' => $this->now->addDay()->toDateTimeString(),
                'expire_at' => $this->now->toDateTimeString(),
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('expire_at');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function testATitleThatIsOnlySpacesIsNotStored(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/announcements', $this->payload(['title' => '   ']))
            ->assertRedirect()
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function testStoppingAComingDownAnnouncementTakesItDownNow(): void
    {
        $announcement = $this->announce('Cut bersama', ['type' => 'all'], $this->now->subHour());

        $this->actingAs($this->administrator)
            ->patch("/admin/announcements/{$announcement->getKey()}/stop")
            ->assertRedirect();

        $fresh = $announcement->fresh();

        $this->assertNotNull($fresh->expire_at);
        $this->assertSame($this->now->toDateTimeString(), $fresh->expire_at->toDateTimeString());
    }

    public function testAnAnnouncementSomebodyCouldAlreadyHaveReadIsNotDeletable(): void
    {
        $announcement = $this->announce('Sudah tayang', ['type' => 'all'], $this->now->subDay());

        $this->actingAs($this->administrator)
            ->delete("/admin/announcements/{$announcement->getKey()}")
            ->assertRedirect()
            ->assertSessionHasErrors('id');

        $this->assertDatabaseHas('announcements', ['id' => $announcement->getKey()]);
    }

    public function testAnAnnouncementThatHasNotGoneUpYetCanBeTakenBack(): void
    {
        $announcement = $this->announce('Salah jadwal', ['type' => 'all'], $this->now->addDay());

        $this->actingAs($this->administrator)
            ->delete("/admin/announcements/{$announcement->getKey()}")
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $announcement->getKey()]);
    }

    public function testTheShellHandsTheBannerToEveryAccountTheAnnouncementAddresses(): void
    {
        // The reader is not an administrator: FSD-208's banner is for the staff the
        // notice is addressed to, and the shell (FSD §2.3) is what draws it, so the
        // list has to be shared with every page rather than rendered by one.
        $announcement = $this->announce('Ruang QC ditutup', ['type' => 'roles', 'roles' => ['qc_supervisor']], $this->now->subHour());

        /** @var User $supervisor */
        $supervisor = User::factory()->role('qc_supervisor')->create();

        $this->actingAs($supervisor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('announcements.0.id', $announcement->getKey())
                ->where('announcements.0.title', 'Ruang QC ditutup')
                ->where('announcements.0.at', $this->now->subHour()->toIso8601String())
            );

        /** @var User $unaddressed */
        $unaddressed = User::factory()->role('mkt')->create();

        $this->actingAs($unaddressed)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('announcements', []));
    }

    public function testDismissingFromTheBannerTakesItOutOfTheNextRequestForNobodyElse(): void
    {
        $announcement = $this->announce('Audit internal', ['type' => 'all'], $this->now->subHour());

        /** @var User $first */
        $first = User::factory()->role('mkt')->create();

        /** @var User $second */
        $second = User::factory()->role('pd_staff')->create();

        $this->actingAs($first)
            ->post("/announcements/{$announcement->getKey()}/dismiss")
            ->assertRedirect();

        $this->actingAs($first)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('announcements', []));

        $this->actingAs($second)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('announcements.0.id', $announcement->getKey()));
    }

    public function testAnAccountCannotDismissSomebodyElsesBanner(): void
    {
        // The route takes the announcement, not a read row, and the account is
        // taken from the session — so the only pair the write can land on is the
        // one the person is actually looking at.
        $announcement = $this->announce('Audit internal', ['type' => 'all'], $this->now->subHour());

        /** @var User $reader */
        $reader = User::factory()->role('mkt')->create();

        $this->actingAs($reader)->post("/announcements/{$announcement->getKey()}/dismiss");

        $this->assertSame(
            [$announcement->getKey()],
            DB::table('announcement_reads')->where('user_id', $reader->getKey())->pluck('announcement_id')->all()
        );
    }

    public function testTheScreenIsInventoriedForAnAccountWhoseCellOpensIt(): void
    {
        // TSD-701's per-screen claim, checked from the menu side: a catalogue entry
        // for a screen nobody can reach is the drift FSD-101 exists to prevent, so
        // the entry has to appear for the same identity that can load the page.
        $this->actingAs($this->administrator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('nav.0.items.2.screen', 'SCR-2.4')
                ->where('nav.0.items.2.label', 'Announcement')
            );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Pengumuman baru',
            'body' => 'Badan pengumuman.',
            'audience' => ['type' => 'all'],
            'publish_at' => $this->now->toDateTimeString(),
            'expire_at' => $this->now->addDays(3)->toDateTimeString(),
        ], $overrides);
    }

    /**
     * @param  list<int>  $ids
     */
    private function departmentIds(string ...$names): array
    {
        return Department::query()->whereIn('name', $names)->orderBy('name')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $audience
     */
    private function announce(string $title, array $audience, CarbonImmutable $publishAt): Announcement
    {
        return Announcement::query()->create([
            'title' => $title,
            'body' => 'Badan pengumuman.',
            'audience' => $audience,
            'publish_at' => $publishAt,
            'expire_at' => null,
            'created_by' => $this->administrator->getKey(),
        ]);
    }

    private function adminActor(): User
    {
        /** @var User $user */
        $user = User::factory()->role('admin')->create(['name' => 'Penjaga Sistem']);

        return $user;
    }
}
