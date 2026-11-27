<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\FeatureGrant;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditService;
use App\Support\Audit\AuditVerb;
use App\Support\Msg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — SCR-2.5, the audit viewer (FSD-207 with FSD-907, BR-10 and TSD-1101).
 *
 * The requirement is one sentence with four parts in it: filters by actor, by
 * action type, by target type and by date range; a row that expands to
 * before/after values; read-only; exportable by report.export. Three of those
 * parts are about a screen, and the fourth is about who may read what it shows,
 * so the tests are written against the trail the services already wrote rather
 * than against a fixture the viewer invented — an audit screen that only ever
 * displays rows its own test put there would prove the layout and nothing else.
 *
 * Two readings of FSD-207 are worth naming, because both decide what a later
 * editor may not "improve":
 *
 * The rows are the ones FSD-907 demanded, so the screen's columns come from the
 * same vocabulary the per-record history strip uses. AuditVerb is the one place
 * an action code becomes Indonesian (TSD §2.3, stage 8), and these tests compare
 * the viewer against that place rather than against a string copied into the
 * screen or into the assertion.
 *
 * The trail cannot be rewritten, so the fixtures that need a row on a specific
 * date INSERT one (the grant TSD-1102 leaves) instead of writing one and then
 * editing its timestamp — the refusal to do the second is itself one of the
 * things this phase is here to prove, and it is proved in
 * Tests\Feature\Audit\AuditImmutabilityTest.
 */
final class AuditViewerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->administrator = $this->adminActor();
    }

    /**
     * FSD-207 with FSD-907 — the row names the actor, the role they held at that
     * moment, and the act in the words the rest of the system uses for it. The
     * role is read from the snapshot on the row, never from the live account: an
     * administrator who is later demoted must not have their history rewritten.
     *
     * Which of the two is evidence, and which is presentation, is the whole
     * question here. The set of roles held at the moment of the act is the
     * evidence and is stored on the row; the words people use for a role are
     * presentation, and §2.2 keeps the role key off the screen — so the reader
     * takes the set from the snapshot and spells each member the way the role
     * list spells it. A chip that still says "admin" after this is not a
     * shortened name: it is the database's identifier in an administrator's
     * sentence.
     */
    public function testTheAuditScreenListsTheTrailNewestFirstWithTheActorAndTheirRoleAtTheTime(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => $department->name]);
        $this->audited(AuditAction::DepartmentUpdated, $department, ['name' => 'Lama'], ['name' => 'Baru']);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Audit')
                ->where('screen', 'SCR-2.5')
                ->where('audit.data.0.action', AuditAction::DepartmentUpdated->value)
                ->where('audit.data.0.verb', AuditVerb::phrase(AuditAction::DepartmentUpdated->value))
                ->where('audit.data.0.actor', 'Penjaga Sistem')
                ->where('audit.data.0.actor_roles', ['Administrator'])
                ->where('audit.data.1.action', AuditAction::DepartmentCreated->value)
                ->where('audit.total', 2)
            );
    }

    /**
     * The other side of the same sentence: a snapshot naming a role the list no
     * longer holds keeps its key, because the alternative is inventing an
     * Indonesian word for a role nobody specified. The history stays readable and
     * the gap stays visible, which is the same bargain the field labels make.
     */
    public function testARoleThatHasLeftTheListIsStillNamedTheWayTheTrailRecordedIt(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        DB::table('audit_entries')->insert([
            'actor_id' => $this->administrator->getKey(),
            'actor_role_snapshot' => json_encode(['admin', 'quality_lead'], JSON_THROW_ON_ERROR),
            'action' => AuditAction::DepartmentUpdated->value,
            'target_type' => $department::class,
            'target_id' => $department->getKey(),
            'before' => json_encode(['name' => 'Lama'], JSON_THROW_ON_ERROR),
            'after' => json_encode(['name' => 'Baru'], JSON_THROW_ON_ERROR),
            'created_at' => now()->toDateTimeString(),
        ]);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.actor_roles', ['Administrator', 'quality_lead'])
            );
    }

    /**
     * FSD-207 "row expands to before/after values" — the pair has to reach the
     * browser, because §2.5's compact strip and this screen's expansion are the
     * same two columns of the same row. Only the changed columns are carried,
     * which is what makes the expansion a decision rather than a snapshot.
     */
    public function testARowCarriesTheBeforeAndAfterValuesItChanged(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->audited(
            AuditAction::DepartmentUpdated,
            $department,
            ['name' => 'Lama', 'is_active' => true],
            ['name' => 'Baru', 'is_active' => false]
        );

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.before', ['name' => 'Lama', 'is_active' => true])
                ->where('audit.data.0.after', ['name' => 'Baru', 'is_active' => false])
            );
    }

    /**
     * FSD-207's first filter. The choice list is the people who actually appear
     * in the trail rather than every account, because "who has done anything
     * worth recording" is the question, and a directory of sixty names where two
     * have acted is the same list with fifty-eight wrong answers.
     */
    public function testTheAuditViewerFiltersByActor(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();
        $other = $this->account('Clerk Satu', 'clerk1@ppm.test');

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'A'], $this->administrator);
        $this->audited(AuditAction::DepartmentUpdated, $department, ['name' => 'A'], ['name' => 'B'], $other);

        $this->actingAs($this->administrator)
            ->get('/admin/audit?actor='.$other->getKey())
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 1)
                ->where('audit.data.0.actor', 'Clerk Satu')
                ->where('audit.filters.actor', (string) $other->getKey())
                ->has('reference.actors', 2)
            );
    }

    /**
     * FSD-207's second filter, and the one that answers the question an audit
     * viewer is opened for: which permission changes, or which decisions. The
     * codes in the URL are the stored values because they are the key the writer
     * used; the label the person reads comes from AuditVerb.
     */
    public function testTheAuditViewerFiltersByActionType(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();
        $user = $this->account('Orang Lain', 'orang@ppm.test');

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'A']);
        $this->audited(AuditAction::UserCreated, $user, null, ['email' => $user->email]);

        $this->actingAs($this->administrator)
            ->get('/admin/audit?action='.AuditAction::UserCreated->value)
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 1)
                ->where('audit.data.0.action', AuditAction::UserCreated->value)
                ->where('audit.filters.action', AuditAction::UserCreated->value)
            );
    }

    /**
     * FSD-207's third filter. The URL carries a short key, not a class name:
     * §2.2 keeps machine values off the screen, and a parameter that names a
     * model is a parameter that can be pointed at one the viewer was not meant
     * to open. The reader-visible half is the Indonesian type label.
     */
    public function testTheAuditViewerFiltersByTargetType(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();
        $user = $this->account('Orang Lain', 'orang@ppm.test');

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'A']);
        $this->audited(AuditAction::UserCreated, $user, null, ['email' => $user->email]);

        $this->actingAs($this->administrator)
            ->get('/admin/audit?target=user')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 1)
                ->where('audit.data.0.target', 'Akun')
                ->where('audit.data.0.target_id', (int) $user->getKey())
                ->where('audit.filters.target', 'user')
            );
    }

    /**
     * FSD-207's fourth filter, both ends included. §2.4's date ranges are day
     * ranges on the wall clock the whole system keeps (TSD §14.2), so a row
     * written at 23:30 on the closing day belongs to it — a range that quietly
     * meant "up to midnight UTC" would hide the end of a working day, which is
     * exactly when the changes an auditor is looking for happen.
     */
    public function testTheAuditViewerFiltersByDateRangeIncludingBothEnds(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-10-31 23:59:30');
        $inside = $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-01 00:00:30');
        $lateEvening = $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-02 23:30:00');
        $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-03 00:00:30');

        $this->actingAs($this->administrator)
            ->get('/admin/audit?from=2026-11-01&to=2026-11-02')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 2)
                ->where('audit.data.0.id', $lateEvening)
                ->where('audit.data.1.id', $inside)
                ->where('audit.total', 2)
            );
    }

    /**
     * A filter the trail has never written must narrow to nothing, not widen to
     * everything. §2.4 says filters combine with AND, and the failure mode of
     * "unknown value, ignore it" is a screen that shows an entire audit trail to
     * someone who asked for one action type and made a typo.
     */
    public function testAFilterValueTheTrailHasNeverWrittenMatchesNothingRatherThanEverything(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'A']);

        $this->actingAs($this->administrator)
            ->get('/admin/audit?action=user.deleted')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 0)
                ->where('audit.total', 0)
                ->where('audit.filters.action', 'user.deleted')
            );
    }

    /**
     * The same rule as the unknown action code, for the filter a person is most
     * likely to type by hand: a date that is not a day narrows to nothing rather
     * than being dropped, so a mistyped range shows an empty screen instead of
     * the whole trail the reader did not ask for.
     */
    public function testADateThatIsNotADayNarrowsTheTrailToNothingRatherThanIgnoringTheFilter(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();
        $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-02 09:00:00');

        $this->actingAs($this->administrator)
            ->get('/admin/audit?from=2-11-2026')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 0)
                ->where('audit.filters.from', '2-11-2026')
            );
    }

    /**
     * TSD §12 — "the viewer always pages server-side" against an audit volume
     * assumption of ~50k rows a year. The first page is one page of rows plus the
     * total the footer needs; nothing in this screen reads the whole table.
     */
    public function testTheAuditTrailIsPagedServerSideAndSaysHowManyRowsItRefusedToShow(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        for ($i = 0; $i < 30; $i++) {
            $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-01 08:00:00');
        }

        $this->actingAs($this->administrator)
            ->get('/admin/audit?per_page=5&page=3')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 5)
                ->where('audit.total', 30)
                ->where('audit.current_page', 3)
                ->where('audit.per_page', 5)
            );
    }

    /**
     * §2.4 is one contract for the 41 lists, and SCR-2.5 is one of them, so the
     * trail arrives wearing the same shape as the account directory: data, total,
     * current_page, last_page, plus the page sizes and the default this screen's
     * server chose. A component that had to know audit rows to page them would be
     * the first of two list tables, which is what the reviewed prototype was.
     *
     * The sizes are literals rather than a read of AuditHistory's constant
     * because §2.4 is the source: the viewer offers four, not five, and defaults
     * to the wider page because a row here expands into a before/after pair.
     */
    public function testTheTrailArrivesInTheShapeEveryOtherListArrivesIn(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        for ($i = 0; $i < 3; $i++) {
            $this->insertEntry($department, AuditAction::DepartmentUpdated, '2026-11-01 08:0'.$i.':00');
        }

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data')
                ->where('audit.total', 3)
                ->where('audit.current_page', 1)
                ->where('audit.last_page', 1)
                ->where('audit.per_page', 25)
                ->where('columns.perPageOptions', [5, 10, 25, 50])
                ->where('columns.defaultPerPage', 25)
                ->where('columns.sortable', [])
                ->where('columns.defaultSort', ['column' => 'created_at', 'direction' => 'desc'])
            );
    }

    /**
     * §2.2 keeps machine values off a screen, and the expansion is the one place
     * the trail shows raw attribute names, so the reader translates them: the
     * field gets the word the form that writes it uses, a flag is Ya or Tidak
     * rather than 1, and a side that has no value yet is empty rather than a
     * null printed as text.
     */
    public function testTheExpandedRowNamesTheChangedFieldInTheWordsTheFormUses(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->audited(
            AuditAction::DepartmentUpdated,
            $department,
            ['name' => 'Lama', 'is_active' => false],
            ['name' => 'Baru', 'is_active' => true]
        );

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.changes', [
                    ['field' => 'Nama', 'from' => 'Lama', 'to' => 'Baru'],
                    ['field' => 'Aktif', 'from' => 'Tidak', 'to' => 'Ya'],
                ])
            );
    }

    /**
     * The matrix is FSD §6's sheet and a level is its only vocabulary, so an
     * audit row about a cell has to say what the cell now means. PermissionLevel
     * owns the three words, the same way it owns the sheet's F and R: the grid
     * uses the letters because it has no room, and nothing else is allowed to
     * spell the levels out by hand.
     */
    public function testAChangeOfAccessLevelIsSpelledTheWayTheMatrixDefinesIt(): void
    {
        $grant = FeatureGrant::query()->orderBy('id')->first();

        $this->audited(AuditAction::MatrixCellChanged, $grant, ['level' => 'NONE'], ['level' => 'READ']);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.changes', [
                    ['field' => 'Level hak akses', 'from' => 'Tidak ada', 'to' => 'Baca saja'],
                ])
            );
    }

    /**
     * Roles are a set, and a set on both sides of a change is one sentence each,
     * not a JSON array dropped in front of a person. The values are the identity
     * names the matrix columns carry, joined in the order they were stored.
     */
    public function testAChangeOfRolesReadsAsTheTwoSetsRatherThanAsAnArray(): void
    {
        $user = $this->account('Orang Lain', 'orang@ppm.test');

        $this->audited(AuditAction::UserRolesAssigned, $user, ['roles' => ['clerk']], ['roles' => ['clerk', 'admin']]);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.changes', [
                    ['field' => 'Peran', 'from' => 'clerk', 'to' => 'clerk, admin'],
                ])
            );
    }

    /**
     * A creation has no before side, and "nothing" is what the expansion should
     * say — an empty string, not the word null and not a repeat of the value.
     */
    public function testACreationLeavesTheBeforeSideOfEveryFieldEmpty(): void
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'Baru', 'is_active' => true]);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->where('audit.data.0.changes', [
                    ['field' => 'Nama', 'from' => null, 'to' => 'Baru'],
                    ['field' => 'Aktif', 'from' => null, 'to' => 'Ya'],
                ])
            );
    }

    /**
     * FSD-101 — the menu entry and the URL are answered from the same cell.
     * SCR-2.5 has no cell of its own in FSD §6, so it belongs to admin.access:
     * READ opens it, NONE is refused with MSG-010 and the attempt is written to
     * the log §5.3 requires.
     */
    public function testAnAccountWithoutAdminAccessIsRefusedTheTrailAndTheAttemptIsLogged(): void
    {
        $outsider = $this->account('Orang Luar', 'luar@ppm.test');

        $this->actingAs($outsider)
            ->get('/admin/audit')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', Msg::get('MSG-010'));

        $denial = DB::table('access_logs')
            ->where('actor_id', $outsider->getKey())
            ->where('route', 'GET admin/audit')
            ->sole();

        $this->assertSame('READ_DENIED', (string) $denial->kind);
    }

    /**
     * FSD-207 "no delete, no edit of audit rows", proven as a routing fact: the
     * trail is reachable by GET and by nothing else, and there is no per-row URL
     * to attach a verb to. A row's before/after pair is already in the page the
     * list returned, so expanding it is a browser action rather than a route —
     * which is how this screen stays writable-free without a policy that says no.
     */
    public function testTheOnlyRouteToTheTrailIsARead(): void
    {
        $audit = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => Str::startsWith($route->uri(), 'admin/audit'))
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(['GET admin/audit'], $audit);
    }

    /**
     * The screen is a read of the trail, so a row cannot be answered at with a
     * verb the FSD forbids — and a parameter that looks like an id must not turn
     * the list into a detail route either.
     */
    public function testTheTrailDoesNotAnswerAnyOtherVerb(): void
    {
        $this->actingAs($this->administrator)
            ->patch('/admin/audit', ['action' => 'user.deleted'])
            ->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->actingAs($this->administrator)
            ->delete('/admin/audit/1')
            ->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * FSD-207 names its third filter by target type, so the type is a word the
     * screen owes the reader for every kind of record it can be asked about. The
     * alternative — printing the class the table stores — is the machine value
     * §2.2 keeps off the screen, and a row shown without a word would be the
     * quiet version of the same mistake.
     */
    public function testTheViewerNamesEveryKindOfRecordTheFilterCanAskFor(): void
    {
        $user = $this->account('Orang Satu', 'satu@ppm.test');
        $department = Department::query()->where('name', 'Packaging Development')->sole();
        $grant = FeatureGrant::query()->orderBy('id')->first();

        $this->audited(AuditAction::UserCreated, $user, null, ['email' => $user->email]);
        $this->audited(AuditAction::DepartmentCreated, $department, null, ['name' => 'A']);
        $this->audited(AuditAction::MatrixCellChanged, $grant, ['level' => 'NONE'], ['level' => 'READ']);

        $this->actingAs($this->administrator)
            ->get('/admin/audit')
            ->assertInertia(fn (Assert $page) => $page
                ->has('audit.data', 3)
                ->where('audit.data.0.target', 'Sel matriks akses')
                ->where('audit.data.1.target', 'Departemen')
                ->where('audit.data.2.target', 'Akun')
            );
    }

    /**
     * §2.2 with FSD-907: the viewer spells an act in Indonesian, and AuditVerb is
     * the one place that decides how. A case the map has not caught up with falls
     * back to its stored code, which is exactly the machine value this sentence
     * forbids — so the guard is over the enum, not over one row.
     */
    public function testEveryActionTheTrailCanWriteHasAnIndonesianPhraseOfItsOwn(): void
    {
        $unspelled = array_values(array_filter(
            AuditAction::cases(),
            static fn (AuditAction $case): bool => AuditVerb::phrase($case->value) === $case->value,
        ));

        $this->assertSame(
            [],
            $unspelled,
            'audit verbs the screen would print as their stored codes: '
            .implode(', ', array_map(static fn (AuditAction $case): string => $case->value, $unspelled))
        );
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audited(
        AuditAction $action,
        Model $target,
        ?array $before,
        ?array $after,
        ?User $actor = null,
    ): void {
        $this->app->make(AuditService::class)->record(
            $actor ?? $this->administrator,
            $action,
            $target,
            $before,
            $after,
        );
    }

    /**
     * A row on a date the writer cannot produce today: the trail is append-only,
     * so the only legitimate way to have a November row and an October row to
     * filter between them is to insert one.
     */
    private function insertEntry(Department $target, AuditAction $action, string $at): int
    {
        $id = (int) DB::table('audit_entries')->insertGetId([
            'actor_id' => $this->administrator->getKey(),
            'actor_role_snapshot' => json_encode(['admin'], JSON_THROW_ON_ERROR),
            'action' => $action->value,
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'before' => json_encode(['name' => 'Lama'], JSON_THROW_ON_ERROR),
            'after' => json_encode(['name' => 'Baru'], JSON_THROW_ON_ERROR),
            'ip' => '127.0.0.1',
            'created_at' => $at,
        ]);

        return $id;
    }

    private function account(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'department_id' => Department::query()->where('name', 'Packaging Development')->sole()->getKey(),
        ]);
    }

    private function adminActor(): User
    {
        $admin = User::factory()->role('admin')->create([
            'name' => 'Penjaga Sistem',
            'department_id' => Department::query()
                ->where('name', 'Packaging Development')
                ->sole()
                ->id,
        ]);

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }
}
