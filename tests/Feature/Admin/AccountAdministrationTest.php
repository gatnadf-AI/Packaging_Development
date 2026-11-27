<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Department;
use App\Models\User;
use App\Support\Msg;
use App\Support\Persistence\RowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — SCR-2.1, the account directory (FSD-201, FSD-202, FSD-203 with TSD-523).
 *
 * These are requirement sentences rather than method names, because the phase
 * gate is answered here: an account the person can actually sign up to, a role
 * set the grid can actually see, and a refusal that says why. Anything a screen
 * could draw wrongly is asserted on the props (TSD-704: the "can" flags are the
 * server's answer, not React's guess); anything the database could get wrong is
 * asserted on the row and on the audit entry that wrote it.
 *
 * One thing is deliberately absent: a test that a person can *delete* an
 * account. There is no such route (TSD §3.1 makes the keys RESTRICT and FSD-202
 * ends access by deactivation), so the delete case is covered by the routes that
 * exist rather than by pretending the missing one is guarded.
 */
final class AccountAdministrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private Department $marketing;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->marketing = Department::query()->where('name', 'Marketing')->sole();
        $this->administrator = $this->adminActor();
    }

    /**
     * FSD-201 — the account and the single-use link, and no password in the form.
     */
    public function testCreatingAnAccountDeliversASingleUseLinkInsteadOfATypedPassword(): void
    {
        $response = $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Rina Kartika',
            'email' => 'Rina.Kartika@PPM.TEST ',
            'department_id' => $this->marketing->id,
            'phone' => '+62 811-2233-4455',
            'roles' => ['rnd_staff'],
        ]);

        $response->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'rina.kartika@ppm.test')->sole();

        $this->assertSame('Rina Kartika', $user->name);
        $this->assertSame($this->marketing->id, $user->department_id);
        $this->assertTrue($user->is_active);
        $response->assertRedirect(route('admin.users.show', $user));

        // The address is stored in the one shape V-101 defines, so the unique
        // rule, the login lookup and the directory all name the same person.
        $this->assertFalse(
            password_verify('password', $user->password),
            'the generated secret has to be per-account'
        );
        $this->assertTrue($user->must_change_password, 'FSD-104: the first login is the forced change');

        // The delivery half of FSD-201: a token exists for exactly this address,
        // which is what makes the link single-use rather than a welcome mail.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'rina.kartika@ppm.test']);

        $this->canonicalize($user);
        $this->assertSame(['rnd_staff'], $user->roles->pluck('name')->all());

        $created = DB::table('audit_entries')->where('action', 'user.created')->sole();
        $after = json_decode($created->after, true);

        $this->assertSame($user->id, (int) $created->target_id);
        $this->assertSame($this->administrator->id, (int) $created->actor_id);
        $this->assertSame('rina.kartika@ppm.test', $after['email']);
        $this->assertSame(['rnd_staff'], $after['roles']);
        $this->assertArrayNotHasKey('password', $after, 'the audit trail never carries a secret');

        $link = DB::table('audit_entries')->where('action', 'user.reset_link_sent')->sole();
        $this->assertTrue(json_decode($link->after, true)['delivered']);
    }

    /**
     * FSD-201's other half of the same sentence: an account may be prepared
     * before the person starts, and preparing it must not mail a link that dies
     * before anyone can use it.
     */
    public function testAnAccountPreparedForLaterDoesNotSendTheLinkYet(): void
    {
        $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Calon Staf QC',
            'email' => 'calon.staf@ppm.test',
            'department_id' => Department::query()->where('name', 'Quality Control')->sole()->id,
            'roles' => ['qc_supervisor'],
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertFalse(
            DB::table('audit_entries')->where('action', 'user.reset_link_sent')->exists(),
            'a link for an account that cannot log in is a link that will be dead before it is needed'
        );

        $this->assertFalse(User::query()->where('email', 'calon.staf@ppm.test')->sole()->is_active);
    }

    /**
     * V-101 — the address has to belong to this company.
     */
    public function testAPersonalAddressIsRefusedWithTheV101Sentence(): void
    {
        $response = $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Orang Luar',
            'email' => 'orang@gmail.com',
            'department_id' => $this->marketing->id,
            'roles' => ['pd_staff'],
        ]);

        $response->assertSessionHasErrors([
            'email' => 'Akun Anda tidak terdaftar di domain ini.',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'orang@gmail.com']);
    }

    /**
     * A lookalike domain is the case the allow-list exists for, and a suffix
     * match would have accepted it.
     */
    public function testALookalikeDomainIsNotASuffixMatch(): void
    {
        $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Tamu Palsu',
            'email' => 'tamu@ppm.test.attacker.net',
            'department_id' => $this->marketing->id,
            'roles' => ['pd_staff'],
        ])->assertSessionHasErrors(['email']);

        // Only the administrator seeded in setUp is left.
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * TSD-523 — the grid says an administrator holds no business approval right,
     * and a role form that could pair the two would say otherwise.
     */
    public function testAdministratorIsNotCombinedWithABusinessRole(): void
    {
        $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Dua Topi',
            'email' => 'dua.topi@ppm.test',
            'department_id' => $this->marketing->id,
            'roles' => ['admin', 'pd_manager'],
        ]);

        $this->assertStringContainsString(
            'administrator tidak dapat memegang peran bisnis',
            $this->errorFor('roles')
        );
        $this->assertDatabaseMissing('users', ['email' => 'dua.topi@ppm.test']);
    }

    /**
     * FSD-201 with FSD-203 — the supplier role needs a supplier bound to the
     * account, and the supplier master is Phase 4. Refusing the role is the
     * honest answer; issuing it would hand out an account that G-14 then refuses
     * on every portal route it tries.
     */
    public function testTheSupplierRoleIsRefusedUntilTheSupplierMasterExists(): void
    {
        $this->actingAs($this->administrator)->post('/admin/users', [
            'name' => 'Pemasok Baru',
            'email' => 'pemasok.baru@ppm.test',
            'department_id' => $this->marketing->id,
            'roles' => ['supplier'],
        ]);

        $this->assertStringContainsString(
            'data induk pemasok',
            $this->errorFor('roles')
        );
        $this->assertDatabaseMissing('users', ['email' => 'pemasok.baru@ppm.test']);
    }

    /**
     * FSD-201 — roles are a set, and changing the set is what the grid reads.
     */
    public function testAssigningRolesRewritesMembershipAndLeavesAPairOfSetsBehind(): void
    {
        $user = User::factory()->role('mkt')->create(['department_id' => $this->marketing->id]);

        $before = $user->row_version;

        $this->actingAs($this->administrator)
            ->put("/admin/users/{$user->id}/roles", [
                'roles' => ['rnd_staff', 'qc_supervisor'],
                'row_version' => $before,
            ])
            ->assertSessionHasNoErrors();

        $this->canonicalize($user);

        $this->assertSame(
            ['qc_supervisor', 'rnd_staff'],
            $user->roles->pluck('name')->sort()->values()->all()
        );
        $this->assertSame(
            $before + 1,
            $user->row_version,
            'TSD-520: a role change is a change to the record'
        );

        $entry = DB::table('audit_entries')
            ->where('action', 'user.roles_assigned')
            ->where('target_id', $user->id)
            ->sole();

        $this->assertSame(
            ['roles' => ['mkt']],
            json_decode($entry->before, true),
            'the log has to show what the person was before, not merely what they are now'
        );
        $this->assertSame(
            ['qc_supervisor', 'rnd_staff'],
            json_decode($entry->after, true)['roles']
        );
    }

    /**
     * FSD-204's trap one level down: an administrator who can strip their own
     * admin role has no route left that can give it back.
     */
    public function testAnAdministratorCannotStripTheirOwnAdministratorRole(): void
    {
        $this->actingAs($this->administrator)
            ->put("/admin/users/{$this->administrator->id}/roles", [
                'roles' => ['pd_staff'],
                'row_version' => $this->administrator->row_version,
            ]);

        $this->assertSame(
            'Anda tidak dapat mencabut peran administrator dari akun Anda sendiri.',
            $this->errorFor('roles')
        );

        $this->canonicalize($this->administrator);
        $this->assertSame(['admin'], $this->administrator->roles->pluck('name')->all());
    }

    /**
     * FSD §2.5 — the version token, or MSG-020 and nothing written. A silent
     * overwrite is named in the requirement as the thing that must not happen.
     */
    public function testAStaleVersionIsRefusedWithMsg020AndWritesNothing(): void
    {
        $user = User::factory()->create(['department_id' => $this->marketing->id]);

        $stale = (int) $user->row_version;

        // Somebody else gets there first, through the same guarded write path.
        RowVersion::save($user, $stale, ['name' => 'Nama Diperbarui Orang Lain']);

        $this->actingAs($this->administrator)
            ->patch("/admin/users/{$user->id}", [
                'name' => 'Nama Saya',
                'email' => $user->email,
                'department_id' => $this->marketing->id,
                'row_version' => $stale,
            ]);

        $this->assertSame(
            Msg::get('MSG-020'),
            $this->errorFor('row_version')
        );
        $this->assertSame(
            'Nama Diperbarui Orang Lain',
            $user->fresh()->name,
            'the other edit survives; a refused save does not reach the row'
        );
    }

    /**
     * FSD-202 — closing an account is a live thing: the sessions go with it, and
     * the log says how many.
     */
    public function testDeactivatingClosesTheOpenSessionsOfThatAccount(): void
    {
        $target = User::factory()->create(['department_id' => $this->marketing->id]);

        // Two devices for the target: one from a real login, one inserted as a
        // device that signed in during an earlier request.
        $this->actingAs($target)->get('/dashboard');
        DB::table('sessions')->insert([
            'id' => 'perangkat-lain',
            'user_id' => $target->id,
            'ip_address' => '10.0.0.2',
            'user_agent' => 'probe',
            'last_activity' => time(),
            'payload' => '',
        ]);

        $open = DB::table('sessions')->where('user_id', $target->id)->count();
        $this->assertSame(2, $open);

        $this->actingAs($this->administrator)
            ->patch("/admin/users/{$target->id}/active", [
                'is_active' => false,
                'row_version' => $target->row_version,
                'confirm' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $target->id)->count(),
            'the FSD-202 promise is that the account stops, not that it fades out'
        );

        $deactivated = DB::table('audit_entries')->where('action', 'user.deactivated')->sole();
        $this->assertSame($target->id, (int) $deactivated->target_id);
        $this->assertSame(['is_active' => false], json_decode($deactivated->after, true));
        $this->assertSame(['is_active' => true], json_decode($deactivated->before, true));

        $closed = DB::table('audit_entries')->where('action', 'session.terminated')->sole();
        $this->assertSame($open, json_decode($closed->after, true)['count']);
    }

    /**
     * FSD-202 — an administrator cannot deactivate the account they are typing
     * on, because the reactivate route sits behind the access being switched off.
     */
    public function testAnAdministratorCannotDeactivateTheirOwnAccount(): void
    {
        $this->actingAs($this->administrator)
            ->patch("/admin/users/{$this->administrator->id}/active", [
                'is_active' => false,
                'row_version' => $this->administrator->row_version,
                'confirm' => '1',
            ]);

        $this->assertSame(
            'Anda tidak dapat menonaktifkan akun Anda sendiri dari layar ini.',
            $this->errorFor('is_active')
        );
        $this->assertTrue($this->administrator->fresh()->is_active);
    }

    /**
     * FSD-202 — the confirmation dialog enumerates what the change touches, so
     * the count has to be answered before the request, from the same place the
     * deactivation itself reads.
     */
    public function testTheDetailPageCarriesTheImpactTheConfirmDialogQuotes(): void
    {
        $target = User::factory()->create(['department_id' => $this->marketing->id]);
        $this->actingAs($target)->get('/dashboard');

        $open = DB::table('sessions')->where('user_id', $target->id)->count();

        $this->inertiaJson("/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('component', 'Admin/UserDetail')
            ->assertJsonPath('props.screen', 'SCR-2.1')
            ->assertJsonStructure(['props' => ['history', 'reference' => ['departments']]])
            ->assertJsonPath('props.impact.sessions', $open)
            ->assertJsonPath('props.user.can_deactivate', true)
            ->assertJsonPath('props.user.can_edit', true);
    }

    /**
     * FSD §2.4 — the search box has a floor, and it is the same floor on both
     * sides of the request: a two-character term is not a filter, and the screen
     * is told so it can show the hint instead of an empty table.
     */
    public function testASearchBelowThreeCharactersDoesNotFilter(): void
    {
        User::factory()->create([
            'name' => 'Yang Dicari',
            'email' => 'yang.dicari@ppm.test',
            'department_id' => $this->marketing->id,
        ]);
        User::factory()->count(2)->create(['department_id' => $this->marketing->id]);

        // Two characters: the floor refuses the term, so every row comes back
        // and the screen is told the term was dropped rather than left to read
        // an unfiltered table as a search that found nothing.
        $this->inertiaJson('/admin/users?q=ya')
            ->assertOk()
            ->assertJsonPath('component', 'Admin/Users')
            ->assertJsonPath('props.filters.q', '')
            ->assertJsonPath('props.filters.search_too_short', true)
            ->assertJsonCount(4, 'props.users.data');

        // Inside the floor, over the two columns the screen marks S.
        $this->inertiaJson('/admin/users?q=dicari')
            ->assertOk()
            ->assertJsonPath('props.filters.q', 'dicari')
            ->assertJsonPath('props.filters.search_too_short', false)
            ->assertJsonCount(1, 'props.users.data')
            ->assertJsonPath('props.users.data.0.email', 'yang.dicari@ppm.test');
    }

    /**
     * FSD §2.4 — one sortable column at a time, and only the page sizes the
     * contract lists. A shareable URL that accepts per_page=5000 turns a
     * directory into an export.
     */
    public function testTheListHonoursOneSortColumnAndThePageSizesItAdvertises(): void
    {
        User::factory()->count(12)->create(['department_id' => $this->marketing->id]);

        $this->inertiaJson('/admin/users?sort=email&direction=desc&per_page=5000')
            ->assertOk()
            ->assertJsonPath('props.filters.per_page', 10)
            ->assertJsonPath('props.filters.sort', 'email')
            ->assertJsonPath('props.filters.direction', 'desc')
            ->assertJsonPath('props.users.per_page', 10)
            ->assertJsonPath('props.users.total', 13)
            ->assertJsonCount(10, 'props.users.data');

        // A made-up sort column is not a column: the contract sorts one column
        // and the server refuses to sort by an expression it did not declare.
        $this->inertiaJson('/admin/users?sort=password')
            ->assertOk()
            ->assertJsonPath('props.filters.sort', 'name');
    }

    /**
     * FSD-101 with TSD-504 — a URL the grid refuses is refused, and §5.3 wants
     * the attempt in access_logs with the grants that produced the answer.
     */
    public function testAPersonWithoutTheAdminCellIsRefusedTheDirectoryAndTheAttemptIsLogged(): void
    {
        $staff = User::factory()->role('pd_staff')->create();

        $this->actingAs($staff)->get('/admin/users')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', Msg::get('MSG-010'));

        $denied = DB::table('access_logs')->sole();

        $this->assertSame('READ_DENIED', $denied->kind);
        $this->assertSame('GET admin/users', $denied->route);

        $snapshot = json_decode($denied->grant_snapshot, true);
        $this->assertSame(['pd_staff'], $snapshot['roles']);
        $this->assertSame('admin.access', $snapshot['feature']);
        $this->assertSame('NONE', $snapshot['held']);
    }

    /**
     * FSD-614 — the directory is reached through one cell, and the cell is held
     * by one identity. This is the same assertion the Phase 1.6 gate makes for
     * every route; here it is made for this one, from the grid rather than from
     * a role list in a controller.
     */
    public function testOnlyTheAdministratorIdentityReachesTheDirectory(): void
    {
        foreach (array_keys((array) config('matrix.identities')) as $slug) {
            $person = User::factory()->role($slug)->create();

            // The administrator's answer is the directory; everyone else's is the
            // redirect the feature guard produces, which needs no page shell to
            // render. Only the first half is asked for in the Inertia protocol,
            // for the reason stated on inertiaJson() below.
            $response = $slug === 'admin'
                ? $this->inertiaAs($person, '/admin/users')
                : $this->actingAs($person)->get('/admin/users');

            if ($slug === 'admin') {
                $response->assertOk();

                continue;
            }

            $response->assertRedirect(route('dashboard'));
            $response->assertSessionHas('error', Msg::get('MSG-010'));
        }
    }

    /**
     * TSD-520's pre-check, as the panel on SCR-2.1 asks for it.
     *
     * The Phase 1.6 gate walk found this endpoint answering 403 for the one
     * identity allowed to call it: the route carries a feature cell, and the
     * controller then asked a policy for an ability that had not been written, so
     * Laravel denied by default (TSD-503). Nobody noticed because the panel asks
     * only when a person reaches for the switch, and no test had pressed it.
     */
    public function testTheUsageCountAnswersThePanelThatIsAboutToAskForADecision(): void
    {
        User::factory()->inDepartment($this->marketing)->create();
        User::factory()->inDepartment($this->marketing)->create();

        $this->actingAs($this->administrator)
            ->getJson("/admin/departments/{$this->marketing->getKey()}/usage")
            ->assertOk()
            ->assertJson([
                'department_id' => (int) $this->marketing->getKey(),
                'name' => 'Marketing',
                'user_count' => 2,
                'row_version' => 1,
            ]);
    }

    /**
     * FSD-206's reference list, written from the panel on SCR-2.1.
     *
     * The department endpoints were reached only by the browser until the Phase
     * 1.6 gate walked them, which is the reason these four sit with the
     * directory rather than in a file of their own: FSD-206 gives the list a
     * requirement and no screen id, so its HTTP contract belongs to the screen
     * that carries it.
     */
    public function testANewDepartmentIsFiledFromThePanelAndReadBackByTheDirectory(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/departments', ['name' => '  Legal & Hubungan Industrial  '])
            ->assertRedirect()
            ->assertSessionHas('status', 'Departemen Legal & Hubungan Industrial ditambahkan.');

        $department = Department::query()->where('name', 'Legal & Hubungan Industrial')->sole();

        $this->assertTrue((bool) $department->is_active);

        $this->assertSame(
            ['name' => 'Legal & Hubungan Industrial', 'is_active' => true],
            json_decode(DB::table('audit_entries')->where('action', 'department.created')->sole()->after, true),
            'the list is client-owned data, so the trail has to show the name as it was written down'
        );

        // The dropdown the next person uses is the reference list the directory
        // screen hands the browser, which is what makes the trimmed name the fact
        // rather than the typed one — and it is the only proof here that the panel
        // and the picker read the same rows.
        $page = $this->inertiaAs($this->administrator, '/admin/users');

        $this->assertSame(200, $page->getStatusCode());

        $names = array_column((array) $page->json('props.reference.departments'), 'name');

        $this->assertContains(
            'Legal & Hubungan Industrial',
            $names,
            'the panel writes the list and the picker reads it; a trimmed name that never reached the dropdown would be two facts about one row'
        );
        $this->assertNotContains(
            '  Legal & Hubungan Industrial  ',
            $names,
            'the spaces somebody left in the box are not part of the company vocabulary'
        );
    }

    public function testTwoDepartmentsThatDifferOnlyBySpacingAreRefusedBeforeTheyReachTheList(): void
    {
        $this->actingAs($this->administrator)
            ->post('/admin/departments', ['name' => 'Marketing'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->administrator)
            ->post('/admin/departments', ['name' => ' Marketing '])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Department::query()->where('name', 'Marketing')->count());
    }

    public function testRenamingAndClosingOneDepartmentInOneSaveAreTwoAuditedActs(): void
    {
        $version = (int) $this->marketing->row_version;

        $this->actingAs($this->administrator)
            ->patch("/admin/departments/{$this->marketing->getKey()}", [
                'name' => 'Marketing & Komunikasi',
                'is_active' => 1,
                'row_version' => $version,
            ])
            ->assertSessionHas('status', 'Daftar departemen disimpan.');

        $renamed = DB::table('audit_entries')->where('action', 'department.updated')->sole();

        // AuditService::changedPair() keeps only the columns that moved, so the
        // name change is reported as a name change and nothing else: the flag is
        // absent rather than repeated at its old value. That is what makes the
        // pair readable as an act instead of as a row dump — and it is the reason
        // a save that switched both at once would have shown both here.
        $this->assertSame(
            ['name' => 'Marketing'],
            json_decode($renamed->before, true)
        );
        $this->assertSame(
            ['name' => 'Marketing & Komunikasi'],
            json_decode($renamed->after, true)
        );

        // Closing it is a different act on the same row, so it is a different
        // audited verb: a reader of the trail has to tell "the name changed" apart
        // from "the list no longer offers it".
        $fresh = Department::query()->findOrFail($this->marketing->getKey());

        $this->actingAs($this->administrator)
            ->patch("/admin/departments/{$fresh->getKey()}", [
                'name' => 'Marketing & Komunikasi',
                'is_active' => 0,
                'row_version' => (int) $fresh->row_version,
                'confirm' => 1,
            ])
            ->assertSessionHasNoErrors();

        $closed = DB::table('audit_entries')->where('action', 'department.deactivated')->sole();

        $this->assertSame(['is_active' => false], json_decode($closed->after, true));
        $this->assertFalse((bool) Department::query()->findOrFail($fresh->getKey())->is_active);
    }

    public function testADepartmentIsNotClosedByARequestBodyThatForgotToSaySo(): void
    {
        // `confirm` is required exactly when the save closes the list, which is the
        // only place on this screen where one field of a form can remove a choice
        // from every dropdown in the system.
        $this->actingAs($this->administrator)
            ->patch("/admin/departments/{$this->marketing->getKey()}", [
                'name' => 'Marketing',
                'is_active' => 0,
                'row_version' => (int) $this->marketing->row_version,
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertTrue((bool) Department::query()->findOrFail($this->marketing->getKey())->is_active);
    }

    public function testAStalePanelOverTheDepartmentListWritesNothing(): void
    {
        $this->actingAs($this->administrator)
            ->patch("/admin/departments/{$this->marketing->getKey()}", [
                'name' => 'Nama baru dari form yang tertinggal',
                'is_active' => 1,
                'row_version' => (int) $this->marketing->row_version + 3,
            ])
            ->assertSessionHasErrors(['row_version' => Msg::get('MSG-020')]);

        $this->assertSame('Marketing', Department::query()->findOrFail($this->marketing->getKey())->name);
        $this->assertFalse(
            DB::table('audit_entries')->where('action', 'department.updated')->exists(),
            'a refused save that still writes a trail row would report a change nobody made'
        );
    }

    /**
     * 1.2c — the HTML answer, which the helper note below withholds until the
     * page exists. It exists now and is built into the manifest, so the screen is
     * also asserted as a document a browser can actually open: the root template
     * references the built entry script, and the page object names the component
     * React mounts. A green props test beside a page nobody can load is the
     * failure mode this closes.
     */
    public function testTheDirectoryRendersAsAPageTheBrowserCanOpen(): void
    {
        $this->actingAs($this->administrator)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('/build/assets/app-', false)
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('Admin/Users')
                    ->has('users.data')
                    ->has('columns.searchFloor')
                    ->has('can.create')
            );
    }

    /**
     * §2.4's five page sizes and the default among them belong to the server, and
     * the screen is handed both. Only the list is worth the trip: a front end that
     * knew the default but not which size the server applied would write
     * per_page=10 into a URL that says nothing, and the address bar stops being
     * the state the next person reads.
     */
    public function testTheDirectoryHandsTheBrowserItsPageSizesAndTheDefaultAmongThem(): void
    {
        $this->actingAs($this->administrator)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.perPageOptions', [5, 10, 25, 50, 100])
                ->where('columns.defaultPerPage', 10)
            );
    }

    /**
     * Spatie caches resolved permissions for the lifetime of the request and the
     * resolver memoises a permission set per matrix version. A test that reads a
     * role set back after changing it has to ask for the fresh answer, or it is
     * asserting a cache.
     */
    private function canonicalize(User $user): void
    {
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $user->unsetRelation('roles');
        $user->refresh();
    }

    /**
     * Ask for the Inertia JSON page object and assert the props on it.
     *
     * These tests are about the props: which rows, in which order, under which
     * can-flags. That is the server's contract (TSD-704: the "can" flags are the
     * server's answer, not React's guess), and the JSON body is the same object
     * the browser is handed.
     *
     * The JSON is asked for rather than the HTML because it needs no page to
     * render: assertInertia() reads the props off assertViewHas('page') — the
     * rendered root view (AssertableInertia.php line 73) — so it only works on
     * the HTML answer, and that answer runs @vite() over the built bundle. The
     * HTML path is asserted once, by testTheDirectoryRendersAsAPageTheBrowserCanOpen,
     * which is the test that would have failed in 1.2b and is the reason a stub
     * component was never written to make the props tests look complete.
     *
     * Asking for the JSON needs both headers: without X-Inertia-Version the
     * middleware compares the absent header with the manifest hash and answers
     * 409 for a client it thinks is stale.
     */
    private function inertiaJson(string $url): TestResponse
    {
        return $this->inertiaAs($this->administrator, $url);
    }

    private function inertiaAs(User $actor, string $url): TestResponse
    {
        return $this->actingAs($actor)->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $this->assetVersion(),
        ]);
    }

    /**
     * The version the middleware will compute for the same request.
     */
    private function assetVersion(): string
    {
        // Resolved through the middleware the routes actually run through, so the
        // header cannot drift from the version it is compared against if
        // HandleInertiaRequests ever overrides version().
        return (string) app(HandleInertiaRequests::class)->version(request());
    }

    /**
     * The sentence a field was refused with.
     *
     * Read off the session rather than off the response: TestResponse forwards an
     * unknown method to the underlying response, so there is no ->session() here
     * to call, and the error bag a redirect left behind is the only place a
     * refusal sentence exists. The same idiom the authentication tests use.
     */
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
