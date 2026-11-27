<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionLevel;
use Database\Seeders\PermissionMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * Phase 0.7 gate (TSD-PDS-003 §5, §13; risk R-06).
 *
 * These assertions are the contract between the approved FSD and the running
 * system. They deliberately read the data file rather than a copy of the
 * numbers in the test, because a test that repeats the expected figure by hand
 * will happily keep passing while the seeded grid drifts.
 */
final class PermissionMatrixSeedTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private array $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = require database_path('seeders/data/permission-matrix.php');

        $this->seedReferenceData();
    }

    public function testTheGridHasFourteenIdentitiesAndTwentyNineFeatures(): void
    {
        $this->assertCount(14, $this->source['roles']);
        $this->assertCount(29, $this->source['features']);

        $this->assertSame(14, count($this->roleSlugs()));
        $this->assertSame(29, count($this->featureKeys()));
    }

    public function testEveryRoleHasExactlyOneRowPerFeatureAndNothingElse(): void
    {
        $expected = count($this->source['roles']) * count($this->source['features']);

        $this->assertSame($expected, DB::table('feature_grants')->count());
        $this->assertSame(0, DB::table('feature_grants')->whereNull('level')->count());
    }

    public function testEverySeededCellMatchesTheDocument(): void
    {
        $mismatches = [];

        foreach ($this->source['grants'] as $roleSlug => $cells) {
            foreach ($cells as $featureKey => $level) {
                $actual = $this->grant($featureKey, $roleSlug);

                if ($actual !== $level) {
                    $mismatches[] = "{$roleSlug}/{$featureKey}: expected {$level}, found {$actual}";
                }
            }
        }

        $this->assertSame([], $mismatches, "Seeded matrix differs from FSD-PDS-002 §6:\\n".implode("\n", $mismatches));
    }

    public function testTheTwelveBusinessRolesAreSeparableFromTheTwoSystemIdentities(): void
    {
        $business = DB::table('roles')->where('is_business', true)->pluck('name')->all();
        $system = DB::table('roles')->where('is_system', true)->pluck('name')->all();

        $this->assertCount(12, $business);
        $this->assertContains('admin', $system);
        $this->assertContains('supplier', $system);
    }

    public function testAdminHoldsNoBusinessApprovalRightEvenThoughItRunsTheSystem(): void
    {
        // FSD §6.1: the administrator can configure the system and cannot
        // approve anything through it.
        foreach (['fab.approve', 'fab.check', 'quotation.decide', 'project.concept'] as $feature) {
            $this->assertSame(
                PermissionLevel::None->value,
                $this->grant($feature, 'admin'),
                "admin must not hold {$feature}"
            );
        }

        $this->assertSame(PermissionLevel::Full->value, $this->grant('admin.access', 'admin'));
    }

    public function testTheSeedIsIdempotentAndCannotWidenADeliberateHandEdit(): void
    {
        // A second run must not double-insert rows nor resurrect a deleted grant.
        $this->seed(PermissionMatrixSeeder::class);
        $this->assertSame(
            count($this->source['roles']) * count($this->source['features']),
            DB::table('feature_grants')->count()
        );

        $versionBefore = DB::table('matrix_versions')->orderByDesc('id')->value('version');
        $this->seed(PermissionMatrixSeeder::class);
        $this->assertGreaterThan(
            $versionBefore,
            DB::table('matrix_versions')->orderByDesc('id')->value('version'),
            're-seeding must bump the matrix version so cached permission sets retire (ADR-08)'
        );
    }

    public function testTheCheckerRoleIsTheOnlyOneThatPassesOnTheFabAndTheManagerApprovesIt(): void
    {
        // The paper form's own logic, asserted rather than assumed: QC Supervisor
        // checks, QC / SCM / QA Managers approve.
        $this->assertSame('FULL', $this->grant('fab.check', 'qc_supervisor'));
        $this->assertSame('READ', $this->grant('fab.check', 'qc_manager'));

        foreach (['qc_manager', 'scm_manager', 'qa_manager'] as $approver) {
            $this->assertSame('FULL', $this->grant('fab.approve', $approver), $approver);
        }

        $this->assertSame('NONE', $this->grant('fab.approve', 'qc_supervisor'));
    }

    public function testSpecificationImportIsExclusiveToQualityControl(): void
    {
        // The row order is not the point; the membership of the set is.
        $importers = DB::table('feature_grants')
            ->where('feature_key', 'specification.import')
            ->where('level', 'FULL')
            ->pluck('role_slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['qc_manager', 'qc_supervisor'], $importers);
        $this->assertCount(
            2,
            $importers,
            'the FSD §6 grid gives import to Quality Control alone; a third holder is a matrix error'
        );
    }
}
