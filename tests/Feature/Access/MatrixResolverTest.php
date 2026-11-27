<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\MatrixResolver;
use App\Domain\Access\MatrixVersion;
use App\Domain\Access\PermissionLevel;
use App\Models\User;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * TSD-PDS-003 §5.1, §5.2 and FSD-614/615 — what a person can do is computed
 * from the seeded grid and nothing else.
 *
 * Q-F6 is the subtle part: two roles widen the rights (union, cell by cell) but
 * must not weaken segregation. These tests hold both halves.
 */
final class MatrixResolverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function testASingleRoleResolvesToItsOwnRowOfTheGrid(): void
    {
        $user = User::factory()->role('qc_supervisor')->create();

        $set = app(MatrixResolver::class)->forUser($user);

        $this->assertSame(PermissionLevel::Full, $set->level('fab.check'));
        $this->assertSame(PermissionLevel::None, $set->level('fab.approve'));
        $this->assertSame(['qc_supervisor'], $set->roleSlugs());
    }

    public function testAnAccountWithNoRolesCanDoNothing(): void
    {
        $user = User::factory()->create();

        $set = app(MatrixResolver::class)->forUser($user);

        $this->assertSame([], $set->viewableFeatures());
        $this->assertFalse($set->canView('report.export'));
    }

    public function testTwoRolesUnionAtTheHigherLevelCellByCell(): void
    {
        // FSD-615 / Q-F6: Marketing (F on project.create, — on inspection) plus
        // QC Supervisor (F on inspection, — on project.create) yields F on both.
        $both = User::factory()->roles(['mkt', 'qc_supervisor'])->create();

        $set = app(MatrixResolver::class)->forUser($both);

        $this->assertSame(PermissionLevel::Full, $set->level('project.create'));
        $this->assertSame(PermissionLevel::Full, $set->level('inspection.manage'));
        $this->assertTrue($set->canAct('project.create'));
        $this->assertTrue($set->canAct('inspection.manage'));
    }

    public function testReadPlusNoneStaysReadAndNeverBecomesFull(): void
    {
        // A role that may only look cannot lend the ability to act to another.
        $user = User::factory()->roles(['bod', 'scm_staff'])->create();

        $set = app(MatrixResolver::class)->forUser($user);

        $this->assertSame('READ', $this->grant('project.create', 'bod'));
        $this->assertSame('NONE', $this->grant('project.create', 'scm_staff'));
        $this->assertSame(PermissionLevel::Read, $set->level('project.create'));
        $this->assertFalse($set->canAct('project.create'));
        $this->assertTrue($set->canView('project.create'));
    }

    public function testADeactivatedAccountLosesEverythingWithoutDeletingItsGrants(): void
    {
        // FSD-202: the role rows stay for the audit history; the person stops.
        $user = User::factory()->inactive()->role('qc_manager')->create();

        $this->assertSame('FULL', $this->grant('fab.approve', 'qc_manager'));
        $this->assertFalse(app(MatrixResolver::class)->forUser($user)->canAct('fab.approve'));
    }

    public function testAChangedCellIsVisibleInTheSameRequestOnceTheVersionBumps(): void
    {
        // ADR-08 + FSD-204: no TTL, one integer.
        $user = User::factory()->role('pd_staff')->create();
        $resolver = app(MatrixResolver::class);

        $this->assertSame(PermissionLevel::Full, $resolver->forUser($user)->level('fab.create'));

        DB::table('feature_grants')
            ->where('feature_key', 'fab.create')
            ->where('role_slug', 'pd_staff')
            ->update(['level' => PermissionLevel::Read->value]);

        MatrixVersion::bump($user->id);
        MatrixResolver::reset();

        $this->assertSame(PermissionLevel::Read, app(MatrixResolver::class)->forUser($user)->level('fab.create'));
    }

    public function testTheMatrixVersionIsAMonotonicIntegerAndNotATimestamp(): void
    {
        DB::table('matrix_versions')->delete();

        MatrixVersion::reset();
        $first = MatrixVersion::current();
        $this->assertSame(0, $first);

        MatrixVersion::bump();
        MatrixVersion::reset();
        $this->assertSame(1, MatrixVersion::current());

        MatrixVersion::bump();
        MatrixVersion::reset();
        $this->assertSame(2, MatrixVersion::current());
    }

    public function testReseedingWithoutClearingDataDoesNotDuplicateGrantRows(): void
    {
        $before = DB::table('feature_grants')->count();

        $this->seed(PermissionMatrixSeeder::class);

        $this->assertSame($before, DB::table('feature_grants')->count());
    }
}
