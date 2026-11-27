<?php

declare(strict_types=1);

namespace Tests\Feature\Notify;

use App\Domain\Notify\ActiveAnnouncements;
use App\Domain\Notify\AnnouncementService;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * FSD-208 (SCR-2.4), the reading half — "active announcements appear as a
 * dismissible banner, remembered dismissed per user per announcement".
 *
 * This is the answer the shell asks for on every request, so it is one function
 * with three parts and no branch of it is left to the browser: the window
 * (is it up right now), the audience (was this person addressed), and the
 * dismissal (has this person already said "Mengerti"). A banner that the client
 * filtered would be a banner whose rules can be changed by editing the page, and
 * the audience is the one part of an announcement that is not cosmetic — it says
 * who is being told something.
 *
 * The window is half-open on purpose: live from publish_at, gone at expire_at.
 * An inclusive end would mean an announcement whose expire_at is 17:00 is still
 * on screen at 17:00:00, and the person reading it at that second would be
 * reading a notice its author had already ended. The instant the author typed is
 * the instant it comes down.
 */
final class AnnouncementVisibilityTest extends TestCase
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

    public function testAnAnnouncementForEveryoneIsUpFromTheMomentItSaidItWouldBe(): void
    {
        $this->publish(['type' => 'all'], publishAt: $this->now->addMinute());

        $this->assertSame([], $this->seenBy(User::factory()->role('mkt')->create()));

        $this->assertCount(1, $this->seenBy(User::factory()->role('mkt')->create(), $this->now->addMinute()));
    }

    public function testTheInstantSomebodyTypedAsTheEndIsTheInstantItComesDown(): void
    {
        $this->publish(['type' => 'all'], publishAt: $this->now->subHour(), expireAt: $this->now);

        $this->assertCount(1, $this->seenBy(User::factory()->role('mkt')->create(), $this->now->subSecond()));
        $this->assertSame([], $this->seenBy(User::factory()->role('mkt')->create(), $this->now));
    }

    public function testARoleAudienceReachesAnybodyHoldingAnyOfTheNamedRoles(): void
    {
        $this->publish(['type' => 'roles', 'roles' => ['qc_supervisor', 'qa_manager']]);

        $this->assertCount(1, $this->seenBy(User::factory()->role('qc_supervisor')->create()));
        $this->assertCount(1, $this->seenBy(User::factory()->role('qa_manager')->create()));
        $this->assertSame([], $this->seenBy(User::factory()->role('qc_manager')->create()));
    }

    public function testAnAccountHoldingTwoRolesHearsAnAnnouncementAddressedToEither(): void
    {
        // FSD-615: two roles widen the rights, and an audience is a question
        // about the rights, so the same union that opens a screen opens a banner.
        $this->publish(['type' => 'roles', 'roles' => ['scm_manager']]);

        $this->assertCount(1, $this->seenBy(User::factory()->roles(['mkt', 'scm_manager'])->create()));
    }

    public function testADepartmentAudienceReachesTheAccountsOnThatDepartment(): void
    {
        $quality = Department::query()->where('name', 'Quality Assurance')->sole();

        $this->publish(['type' => 'departments', 'departments' => [$quality->id]]);

        $this->assertCount(1, $this->seenBy(User::factory()->role('qa_manager')->create([
            'department_id' => $quality->id,
        ])));

        $this->assertSame([], $this->seenBy(User::factory()->role('qa_manager')->create([
            'department_id' => Department::query()->where('name', 'Quality Control')->sole()->id,
        ])));
    }

    public function testAnAccountWithoutADepartmentIsNotAnAccountOfEveryDepartment(): void
    {
        $quality = Department::query()->where('name', 'Quality Assurance')->sole();

        $this->publish(['type' => 'departments', 'departments' => [$quality->id]]);

        $this->assertSame([], $this->seenBy(User::factory()->role('qa_manager')->create([
            'department_id' => null,
        ])));
    }

    public function testAPersonWhoSaidMengertiStopsSeeingItAndNobodyElseDoes(): void
    {
        $announcement = $this->publish(['type' => 'all']);

        /** @var User $alreadyRead */
        $alreadyRead = User::factory()->role('mkt')->create();

        /** @var User $notYet */
        $notYet = User::factory()->role('mkt')->create();

        app(AnnouncementService::class)->dismiss($announcement, $alreadyRead, $this->now);

        $this->assertSame([], $this->seenBy($alreadyRead));
        $this->assertCount(1, $this->seenBy($notYet));
    }

    public function testTheNewestWordIsOnTop(): void
    {
        $this->publish(['type' => 'all'], publishAt: $this->now->subDays(2), title: 'Yang lama');
        $this->publish(['type' => 'all'], publishAt: $this->now->subDay(), title: 'Yang terbaru');

        $seen = $this->seenBy(User::factory()->role('mkt')->create());

        $this->assertSame(['Yang terbaru', 'Yang lama'], array_column($seen, 'title'));
    }

    public function testTheBannerIsHandedTheWordsAndNotTheRecord(): void
    {
        // The shape the shell renders: what to show, and the key to dismiss by.
        // An audience in here would be a second filter the browser could apply.
        $announcement = $this->publish(['type' => 'all']);

        $seen = $this->seenBy(User::factory()->role('mkt')->create());

        $this->assertSame(
            [
                'id' => $announcement->getKey(),
                'title' => 'Judul pengumuman',
                'body' => 'Badan pengumuman.',
                'at' => $this->now->subHour()->toIso8601String(),
            ],
            $seen[0]
        );
    }

    public function testNothingIsRenderedWhenNothingIsUp(): void
    {
        $this->publish(['type' => 'all'], publishAt: $this->now->addDay());

        $this->assertSame([], $this->seenBy(User::factory()->role('mkt')->create()));
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return \App\Models\Announcement
     */
    private function publish(array $audience, ?CarbonImmutable $publishAt = null, ?CarbonImmutable $expireAt = null, string $title = 'Judul pengumuman')
    {
        return app(AnnouncementService::class)->create([
            'title' => $title,
            'body' => 'Badan pengumuman.',
            'audience' => $audience,
            'publish_at' => $publishAt ?? $this->now->subHour(),
            'expire_at' => $expireAt,
        ], $this->actor, $publishAt ?? $this->now->subHour());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seenBy(User $user, ?CarbonImmutable $now = null): array
    {
        return ActiveAnnouncements::forUser($user, $now ?? $this->now);
    }
}
