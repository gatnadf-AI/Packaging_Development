<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AccessLog;
use App\Models\AuditEntry;
use App\Models\Department;
use App\Models\User;
use App\Support\Audit\AccessLogKind;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditGrants;
use App\Support\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\SeedsReferenceData;
use Tests\TestCase;

/**
 * F2 — BR-10 with TSD-1102: the trail is append-only, and the refusal is not
 * left to the discipline of whoever writes the next service.
 *
 * The rule is enforced twice, on purpose, and this file covers the first of the
 * two. What is tested here is the model: an accidental $entry->save() in
 * application code has to fail loudly, in the same sentence, on every machine —
 * including the test schema, where the database answer below cannot be installed
 * because RefreshDatabase rebuilds the tables and their grants from nothing.
 *
 * The second half is the database grant, and it is the one TSD-1102's sentence
 * is actually about ("even a bug that issues an UPDATE fails at the database").
 * It is installed by database/provision/app-grants.sql, which takes schema-level
 * DML away from the application account and gives it back table by table except
 * on audit_entries and access_logs, and it is read back by `php artisan
 * pds:doctor`: the doctor says which of the two the running schema actually has.
 * A statement that bypasses Eloquent — a query-builder update from a tinker
 * session, a queue job holding the wrong connection — is the database's to stop,
 * and the only honest proof of that is the doctor on the deployed schema rather
 * than a test that would pass for the wrong reason on a rebuilt one.
 */
final class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function testTheModelRefusesToChangeAnAuditRow(): void
    {
        $entry = AuditEntry::findOrFail($this->auditRow());

        $this->assertRefused(fn () => $entry->update(['action' => AuditAction::UserDeactivated->value]));

        $this->assertDatabaseHas('audit_entries', [
            'id' => $entry->getKey(),
            'action' => AuditAction::DepartmentUpdated->value,
        ]);
    }

    public function testTheModelRefusesToDeleteAnAuditRow(): void
    {
        $entry = AuditEntry::findOrFail($this->auditRow());

        $this->assertRefused(fn () => $entry->delete());

        $this->assertDatabaseHas('audit_entries', ['id' => $entry->getKey()]);
    }

    /**
     * The read log is append-only for the same reason (FSD-908: the record of
     * who knocked is evidence, and evidence that can be tidied is not evidence).
     */
    public function testTheModelRefusesToChangeAnAccessLogRow(): void
    {
        $log = AccessLog::findOrFail($this->accessRow());

        $this->assertRefused(fn () => $log->update(['kind' => 'WRITTEN_BY_SOMEBODY_ELSE']));

        $this->assertDatabaseHas('access_logs', [
            'id' => $log->getKey(),
            'kind' => AccessLogKind::ReadDenied->value,
        ]);
    }

    public function testTheModelRefusesToDeleteAnAccessLogRow(): void
    {
        $log = AccessLog::findOrFail($this->accessRow());

        $this->assertRefused(fn () => $log->delete());

        $this->assertDatabaseHas('access_logs', ['id' => $log->getKey()]);
    }

    /**
     * The refusal names the rule, because a stack trace that says "data not
     * mutable" invites the next person to look for a flag to turn off.
     */
    public function testTheRefusalSaysWhichRuleItIsProtecting(): void
    {
        $entry = AuditEntry::findOrFail($this->auditRow());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('TSD-1102');

        $entry->delete();
    }

    /**
     * The guard is a refusal of two verbs, not a lock on the table: a service
     * that cannot append a row has lost the audit rather than protected it, and
     * the failure would look like a clean system with no history.
     */
    public function testTheApplicationIsStillAllowedToAddToTheTrail(): void
    {
        $user = User::factory()->role('admin')->create();

        $this->app->make(AuditService::class)->record(
            $user,
            AuditAction::DepartmentCreated,
            Department::query()->where('name', 'Packaging Development')->sole(),
            null,
            ['name' => 'Baru'],
        );

        $this->assertDatabaseCount('audit_entries', 1);
    }

    /**
     * The reading half of the rule, run against the schema the suite stands on.
     * pds_test is provisioned with schema-level DML on purpose — RefreshDatabase
     * has to be able to rebuild these tables and their grants from nothing — so
     * the honest verdict here is FAIL. That is the assertion worth having: the
     * reader has to say so out loud rather than settle for per-table rows it never
     * asked for, which is the mistake the first version of
     * database/provision/app-grants.sql made and this reading exists to catch.
     * Should the test schema ever be given the deployed shape, this expectation
     * becomes OK; the reader does not care which of the two it finds, only that it
     * reports the one it did.
     */
    public function testTheReaderReportsTheGrantsTheTestSchemaActuallyHas(): void
    {
        $verdict = AuditGrants::inspect();

        $this->assertSame('FAIL', $verdict['state']);
        $this->assertStringContainsString('at schema level', $verdict['detail']);
    }

    private function assertRefused(callable $write): void
    {
        try {
            $write();
        } catch (LogicException $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('application code was allowed to rewrite the trail');
    }

    private function auditRow(): int
    {
        $department = Department::query()->where('name', 'Packaging Development')->sole();

        $this->app->make(AuditService::class)->record(
            User::factory()->role('admin')->create(),
            AuditAction::DepartmentUpdated,
            $department,
            ['name' => 'Lama'],
            ['name' => 'Baru'],
        );

        return (int) DB::table('audit_entries')->max('id');
    }

    private function accessRow(): int
    {
        $this->app->make(AuditService::class)->logAccess(
            User::factory()->create(),
            AccessLogKind::ReadDenied,
            null,
            'GET admin/audit',
            ['feature' => 'admin.access', 'held' => 'NONE'],
        );

        return (int) DB::table('access_logs')->max('id');
    }
}
