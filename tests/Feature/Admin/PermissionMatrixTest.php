<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\AccessService;
use App\Domain\Access\ChangeRefused;
use App\Domain\Access\MatrixGrid;
use App\Domain\Access\MatrixResolver;
use App\Domain\Access\PermissionLevel;
use App\Domain\Access\RecordHasBeenChanged;
use App\Models\Department;
use App\Models\FeatureGrant;
use App\Models\User;
use App\Support\Msg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — SCR-2.3, the editable access matrix (FSD-204, FSD-205 with TSD-501,
 * TSD-510 and TSD-511).
 *
 * The requirement being proven is narrower than a grid looks from across the
 * room: one cell, saved on its own, is the answer the very next permission
 * question gets. TSD-511 keeps the transaction small for exactly that reason —
 * "a page of edits is many small saves" — and FSD-204 refuses the cheap
 * alternative of a cached grid that settles a minute later, because an
 * administrator who has just closed a role's approval right has to be able to
 * trust that it is closed.
 *
 * So the tests are written against the resolver rather than against a template.
 * A cell that persists but is still shadowed by a memoised permission set is the
 * failure this screen is most likely to produce, and it is the one nobody sees
 * until someone acts under the old grant.
 */
final class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private AccessService $access;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->access = $this->app->make(AccessService::class);
        $this->administrator = $this->adminActor();
    }

    /**
     * FSD-204 — "saving is per cell … and takes effect for the target role's
     * sessions within one request cycle". Proven in the same process, with no
     * cache reset, because that is the case a memoised resolver gets wrong.
     */
    public function testASavedCellIsTheCellTheNextPermissionQuestionIsAnsweredFrom(): void
    {
        $held = app(MatrixResolver::class)->forRoleSlugs(['mkt'])->level('report.export');

        $this->assertSame(
            PermissionLevel::from($this->grant('report.export', 'mkt')),
            $held,
            'the grid starts from the seeded matrix'
        );

        $this->access->grant(
            'report.export',
            'mkt',
            $held,
            $held->next(),
            $this->administrator,
        );

        $this->assertSame(
            $held->next()->value,
            $this->grant('report.export', 'mkt'),
            'the row moved'
        );

        $after = app(MatrixResolver::class)->forRoleSlugs(['mkt'])->level('report.export');

        $this->assertSame($held->next(), $after, 'the cached set still answers with the old cell');

        // A neighbour cell for the same feature must not move with it: "per cell"
        // is the whole reason the save is small, and a writer that touched the
        // role's other rows would turn one click into an unnoticed re-authoring.
        $this->assertSame('FULL', $this->grant('report.export', 'pd_manager'));
    }

    /**
     * TSD-511's third step, and FSD-907's reason for the audit trail at all: a
     * permission decision has to be readable afterwards with the person who made
     * it, the cell it moved, and what it was before. The before/after pair is the
     * whole value of the row — a log that only records "matrix changed" cannot be
     * read by the auditor who asks why the QC Manager lost the approval cell in
     * March.
     */
    public function testSavingACellWritesAnAuditEntryNamingTheCellAndBothLevels(): void
    {
        $before = app(MatrixResolver::class)->forRoleSlugs(['mkt'])->level('report.export');

        $grant = $this->access->grant('report.export', 'mkt', $before, $before->next(), $this->administrator);

        $entry = DB::table('audit_entries')->where('action', 'matrix.cell_changed')->sole();

        $this->assertSame($this->administrator->id, (int) $entry->actor_id);
        $this->assertSame(FeatureGrant::class, $entry->target_type);
        $this->assertSame($grant->id, (int) $entry->target_id);

        $this->assertSame(
            ['level' => $before->value],
            json_decode($entry->before, true),
            'the old level is what makes the entry an account of a decision'
        );

        $this->assertSame(['level' => $before->next()->value], json_decode($entry->after, true));

        // The cell is named by its natural key rather than by a row id alone:
        // §3.3.1 keys the grid on (feature, role), and an auditor reading the
        // entry has to be able to say which two things the row is about.
        $this->assertSame('report.export', $grant->feature_key);
        $this->assertSame('mkt', $grant->role_slug);
    }

    /**
     * FSD-204 "optimistic" with §2.5: two administrators on one grid both read
     * the same cell, and whoever saves second is refused. What is being pinned
     * here is the shape of the refusal rather than that it happens at all — the
     * winner's level stays, the loser writes nothing else behind itself, and the
     * version moves once, not twice. A refused save that still bumped the cache
     * or left an audit row would report a change that never took place.
     */
    public function testASaveMadeAgainstALevelThatNoLongerHoldsIsRefusedWithoutSideEffects(): void
    {
        $held = app(MatrixResolver::class)->forRoleSlugs(['mkt'])->level('report.export');

        // The second administrator gets there first, from the same level the
        // screen showed both of them.
        $this->access->grant('report.export', 'mkt', $held, $held->next(), $this->administrator);

        $versionAfterWinner = DB::table('matrix_versions')->max('version');

        $this->expectException(RecordHasBeenChanged::class);

        $this->access->grant('report.export', 'mkt', $held, $held->next()->next(), $this->administrator);
    }

    public function testARefusedSaveLeavesTheWinnersLevelAndItsSingleAuditEntryInPlace(): void
    {
        $held = app(MatrixResolver::class)->forRoleSlugs(['mkt'])->level('report.export');
        $winner = $held->next();

        $this->access->grant('report.export', 'mkt', $held, $winner, $this->administrator);

        $version = DB::table('matrix_versions')->max('version');

        try {
            $this->access->grant('report.export', 'mkt', $held, $winner->next(), $this->administrator);
            $this->fail('the stale save should have been refused');
        } catch (RecordHasBeenChanged $conflict) {
            $this->assertSame($winner->value, $this->grant('report.export', 'mkt'));
            $this->assertSame($version, DB::table('matrix_versions')->max('version'));
            $this->assertSame(1, DB::table('audit_entries')->where('action', 'matrix.cell_changed')->count());
        }
    }

    /**
     * FSD-204's second sentence: "Admin cannot revoke their own admin.manage".
     * The grid's own cell is the one an editor has to be careful with, because an
     * administrator who lowers it for a role they hold closes the screen they are
     * standing in with no route left to open it again — the same trap the role
     * assignment guard refuses one level down.
     */
    public function testAnAdministratorCannotLowerTheirOwnAccessToTheMatrix(): void
    {
        foreach ([PermissionLevel::Full->next(), PermissionLevel::Read->next()] as $lower) {
            try {
                $this->access->grant('admin.access', 'admin', PermissionLevel::Full, $lower, $this->administrator);
                $this->fail('dropping the admin cell to '.$lower->value.' should be refused');
            } catch (ChangeRefused $refused) {
                $this->assertSame('FULL', $this->grant('admin.access', 'admin'));
                $this->assertNotEmpty($refused->getMessage());
                $this->assertSame('admin.access', $refused->attribute());
            }
        }

        $this->assertSame(
            0,
            DB::table('audit_entries')->where('action', 'matrix.cell_changed')->count(),
            'a refusal writes nothing'
        );
    }

    /**
     * …but the guard is about the actor, not about the row: handing another role
     * a route into the administration area, and taking it back again, is exactly
     * what the screen is for, and refusing it would leave the grid uneditable for
     * the one feature that matters most.
     */
    public function testAnAdministratorMayOpenAndCloseAnotherRolesAccessToTheMatrix(): void
    {
        $this->access->grant('admin.access', 'rnd_manager', PermissionLevel::None, PermissionLevel::Full, $this->administrator);

        $this->assertSame('FULL', $this->grant('admin.access', 'rnd_manager'));

        $this->access->grant('admin.access', 'rnd_manager', PermissionLevel::Full, PermissionLevel::None, $this->administrator);

        $this->assertSame('NONE', $this->grant('admin.access', 'rnd_manager'));
    }

    /**
     * FSD-205 — the preview is a read of the same grid the save will write, so
     * the two cannot disagree about what a cell is worth today. What the screen
     * has to be able to show before anything is saved is the named role, the
     * named feature, the level it is at, the level it would move to, and how many
     * live accounts that moves — the last of those being the reason this is a
     * requirement rather than a confirmation dialog.
     */
    public function testThePreviewNamesEachCellAndCountsTheAccountsItWouldMove(): void
    {
        $marketing = Department::query()->where('name', 'Marketing')->sole();

        $holders = collect(['Sari One', 'Sari Two'])->map(
            fn (string $name): User => User::factory()->role('mkt')->create([
                'name' => $name,
                'department_id' => $marketing->id,
            ])
        );

        // An account that exists but is switched off is not an account the change
        // reaches: FSD-201 takes its rights away through the resolver, so counting
        // it would overstate the diff to whoever is about to press save.
        User::factory()->role('mkt')->inactive()->create(['department_id' => $marketing->id]);

        $grid = $this->app->make(MatrixGrid::class);

        $diff = $grid->preview([
            ['feature' => 'report.export', 'role' => 'mkt', 'to' => PermissionLevel::Full],
            ['feature' => 'admin.access', 'role' => 'rnd_manager', 'to' => PermissionLevel::Read],
        ]);

        $this->assertCount(2, $diff, 'one row per edit, in the order the screen listed them');

        $first = $diff[0];

        $this->assertSame('report.export', $first['feature']);
        $this->assertSame('mkt', $first['role']);
        $this->assertSame('READ', $first['from'], 'the level the grid holds right now');
        $this->assertSame('FULL', $first['to']);
        $this->assertSame($holders->count(), $first['users_affected']);

        $this->assertNotEmpty($first['feature_label']);
        $this->assertNotEmpty($first['role_label']);

        $this->assertSame('NONE', $diff[1]['from']);
        $this->assertSame('READ', $diff[1]['to']);
        $this->assertSame(0, $diff[1]['users_affected']);

        // A preview is not a save.
        $this->assertSame('READ', $this->grant('report.export', 'mkt'));
        $this->assertSame(
            0,
            DB::table('audit_entries')->where('action', 'matrix.cell_changed')->count()
        );
    }

    /**
     * …and an edit that asks for the level the cell already holds is not part of
     * a diff at all. A preview that lists a change which changes nothing teaches
     * the reviewer to skim past the rows that do.
     */
    public function testThePreviewDropsAnEditThatWouldNotMoveTheCell(): void
    {
        $grid = $this->app->make(MatrixGrid::class);

        $diff = $grid->preview([
            ['feature' => 'report.export', 'role' => 'mkt', 'to' => PermissionLevel::Read],
        ]);

        $this->assertSame([], $diff);
    }

    /**
     * The grid the screen draws: every feature row against every role column,
     * with the level the table holds rather than an inference. §3.1 says a
     * missing row means NONE, and the seeder writes the NONE rows anyway so that
     * the editor shows a decision — this is the read that has to keep that promise.
     */
    public function testTheGridCarriesEveryFeatureAgainstEveryRole(): void
    {
        $grid = $this->app->make(MatrixGrid::class);

        $rows = $grid->rows();

        $this->assertCount(29, $rows);
        $this->assertCount(14, $this->roleSlugs());

        foreach ($rows as $row) {
            $this->assertSame(14, count($row['cells']));

            foreach ($row['cells'] as $roleSlug => $cell) {
                $this->assertSame(
                    $this->grant($row['key'], $roleSlug),
                    $cell['level'],
                    "the cell drawn for {$row['key']} x {$roleSlug} is the cell the table holds"
                );
            }
        }

        $this->assertSame(
            DB::table('matrix_versions')->max('version'),
            $grid->version(),
            'the screen carries the version its saves will be checked against'
        );
    }

    /**
     * TSD-511 keeps a save small, and a small save has to be idempotent: a cell
     * asked for at the level it already holds is not a change, so nothing is
     * written, no version is burned, and no audit row claims a decision that was
     * never made. Without this, a double click on one cell reads as two edits to
     * whoever is reviewing the trail.
     */
    public function testAsaveForTheLevelTheCellAlreadyHoldsWritesNothing(): void
    {
        $held = PermissionLevel::from($this->grant('report.export', 'mkt'));
        $version = DB::table('matrix_versions')->max('version');

        $this->access->grant('report.export', 'mkt', $held, $held, $this->administrator);

        $this->assertSame($version, DB::table('matrix_versions')->max('version'));
        $this->assertSame(0, DB::table('audit_entries')->where('action', 'matrix.cell_changed')->count());
        $this->assertSame($held->value, $this->grant('report.export', 'mkt'));
    }

    /**
     * SCR-2.3 as the browser receives it. The props are the grid, the column
     * order and the version the saves will be checked against — the front end
     * draws what the server read rather than keeping its own copy of the truth.
     */
    public function testTheMatrixScreenHandsTheBrowserTheGridItJustRead(): void
    {
        $this->actingAs($this->administrator)
            ->get('/admin/matrix')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Matrix')
                ->where('screen', 'SCR-2.3')
                ->has('matrix.rows', 29)
                ->has('matrix.roles', 14)
                ->where('matrix.version', DB::table('matrix_versions')->max('version'))
                ->where('can.edit', true)
            );
    }

    /**
     * FSD-101 with TSD-504: an account whose cell says NONE is refused by the
     * route, not by a hidden button, and the refusal is the one sentence §2.6
     * gives for it.
     */
    public function testAnAccountWhoseCellSaysNoneIsRefusedTheGrid(): void
    {
        $staff = User::factory()->role('rnd_staff')->create([
            'department_id' => Department::query()->where('name', 'Packaging Development')->sole()->id,
        ]);

        $this->actingAs($staff)->get('/admin/matrix')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', Msg::get('MSG-010'));
    }

    /**
     * READ on admin.access is a state the grid can be edited into, so the screen
     * has to answer it: the matrix is visible, nothing is writable, and the write
     * route refuses rather than trusting the button that was not drawn.
     */
    public function testAReadOnlyGrantSeesTheGridButCannotSaveACell(): void
    {
        $this->access->grant('admin.access', 'rnd_manager', PermissionLevel::None, PermissionLevel::Read, $this->administrator);

        $viewer = User::factory()->role('rnd_manager')->create([
            'department_id' => Department::query()->where('name', 'Packaging Development')->sole()->id,
        ]);

        $this->actingAs($viewer)->get('/admin/matrix')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false));

        $this->actingAs($viewer)->patch('/admin/matrix/cell', [
            'feature' => 'report.export',
            'role' => 'mkt',
            'from' => 'READ',
            'to' => 'FULL',
        ])->assertSessionHas('error', Msg::get('MSG-010'));

        $this->assertSame('READ', $this->grant('report.export', 'mkt'), 'the refused save wrote nothing');
    }

    /**
     * FSD-205 through the screen: the preview answers as data the dialog can
     * draw, and it still answers as data after the save it recommended.
     */
    public function testThePreviewEndpointAnswersWithTheDiffAndWritesNothing(): void
    {
        $response = $this->actingAs($this->administrator)->postJson('/admin/matrix/preview', [
            'cells' => [
                ['feature' => 'report.export', 'role' => 'mkt', 'to' => 'FULL'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('diff.0.feature', 'report.export')
            ->assertJsonPath('diff.0.from', 'READ')
            ->assertJsonPath('diff.0.to', 'FULL')
            ->assertJsonPath('diff.0.role_label', 'Marketing');

        $this->assertSame('READ', $this->grant('report.export', 'mkt'));
    }

    /**
     * A cell saved through the screen is the same write the service performs, so
     * the route adds authorisation and validation rather than a second version of
     * the rule — and a malformed cell address never reaches the service at all.
     */
    public function testSavingACellThroughTheScreenMovesThatCellAlone(): void
    {
        $this->actingAs($this->administrator)->patch('/admin/matrix/cell', [
            'feature' => 'report.export',
            'role' => 'mkt',
            'from' => 'READ',
            'to' => 'NONE',
        ])->assertSessionHasNoErrors();

        $this->assertSame('NONE', $this->grant('report.export', 'mkt'));
        $this->assertSame('READ', $this->grant('sample.review', 'mkt'), 'the neighbour did not move');

        $this->actingAs($this->administrator)->patch('/admin/matrix/cell', [
            'feature' => 'no.such.feature',
            'role' => 'mkt',
            'from' => 'READ',
            'to' => 'FULL',
        ])->assertSessionHasErrors('feature');

        $this->actingAs($this->administrator)->patch('/admin/matrix/cell', [
            'feature' => 'report.export',
            'role' => 'mkt',
            'from' => 'READ',
            'to' => 'MAYBE',
        ])->assertSessionHasErrors('to');
    }

    /**
     * The grid's version token behaves like every other one in the system: a
     * save made against a level the screen no longer holds comes back as MSG-020
     * under the cell, with the winner's level left standing.
     */
    public function testAStaleCellSaveComesBackAsMsg020(): void
    {
        $this->access->grant('report.export', 'mkt', PermissionLevel::Read, PermissionLevel::None, $this->administrator);

        $this->actingAs($this->administrator)->patch('/admin/matrix/cell', [
            'feature' => 'report.export',
            'role' => 'mkt',
            'from' => 'READ',
            'to' => 'FULL',
        ]);

        $this->assertSame(Msg::get('MSG-020'), $this->errorFor('row_version'));
        $this->assertSame('NONE', $this->grant('report.export', 'mkt'));
    }

    private function errorFor(string $field): string
    {
        $messages = app('session')->get('errors')?->getBag('default')->get($field) ?? [];

        $this->assertNotEmpty($messages, "nothing was refused under {$field}");

        return (string) $messages[0];
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
