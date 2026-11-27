<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Audit;

use App\Support\Audit\AuditGrants;
use PHPUnit\Framework\TestCase;

/**
 * F2 — BR-10 with TSD-1102, the reading half.
 *
 * `php artisan pds:doctor` cannot test the database the way a feature test can:
 * it is a program running as the account under examination, and the only honest
 * proof it can offer is what the grant tables say. That reading is what this file
 * pins, because the mistake it has to catch is subtle and silent — a schema that
 * looks append-only at table level while schema-level DML still stands underneath
 * it, which is exactly the state the first version of database/provision/app-grants.sql
 * left behind and reported as done.
 *
 * The writing half — an UPDATE that really is refused — is proven against the
 * deployed schema by this verdict, not by a test: RefreshDatabase rebuilds the
 * test schema and its grants from nothing on every run, so a test asserting the
 * grant would pass for the wrong reason on one machine and fail for an unrelated
 * one on another. Tests\Feature\Audit\AuditImmutabilityTest covers the model guard
 * that the suite can hold.
 */
final class AuditGrantsTest extends TestCase
{
    /** @var list<string> */
    private const TRAIL = ['audit_entries', 'access_logs'];

    public function testSchemaLevelWritePrivilegesOverrideEveryPerTableRow(): void
    {
        $verdict = AuditGrants::judge(
            ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            ['audit_entries' => ['SELECT', 'INSERT'], 'access_logs' => ['SELECT', 'INSERT']],
            self::TRAIL,
        );

        $this->assertSame('FAIL', $verdict['state']);
        $this->assertStringContainsString('at schema level', $verdict['detail']);
        $this->assertStringContainsString('app-grants.sql', $verdict['detail']);
    }

    public function testTheTrailIsGuardedOnceEachTableIsAnsweredOnItsOwn(): void
    {
        $verdict = AuditGrants::judge(
            [],
            [
                'audit_entries' => ['SELECT', 'INSERT'],
                'access_logs' => ['SELECT', 'INSERT'],
                'users' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            ],
            ['audit_entries', 'access_logs', 'users'],
        );

        $this->assertSame('OK', $verdict['state']);
        $this->assertStringContainsString('audit_entries', $verdict['detail']);
        $this->assertStringContainsString('access_logs', $verdict['detail']);
    }

    /**
     * A table-level row is not the same as a table-level refusal: the grant can
     * be the wrong one and still be a grant, which is what makes a half-applied
     * provisioning script look finished.
     */
    public function testATableRowThatStillGrantsUpdateOrDeleteIsNotImmutability(): void
    {
        $verdict = AuditGrants::judge(
            [],
            [
                'audit_entries' => ['SELECT', 'INSERT', 'UPDATE'],
                'access_logs' => ['SELECT', 'INSERT'],
            ],
            self::TRAIL,
        );

        $this->assertSame('FAIL', $verdict['state']);
        $this->assertStringContainsString('audit_entries', $verdict['detail']);
        $this->assertStringNotContainsString('access_logs', $verdict['detail']);
    }

    /**
     * A trail table that the account cannot read is not a protected trail; it is
     * a viewer that throws. Both halves of "SELECT and INSERT, nothing else" have
     * to be present, so a missing grant is reported rather than mistaken for the
     * rule holding.
     */
    public function testATrailTableWithNoGrantOfItsOwnIsRefusedRatherThanProtected(): void
    {
        $verdict = AuditGrants::judge(
            [],
            ['audit_entries' => ['SELECT', 'INSERT']],
            self::TRAIL,
        );

        $this->assertSame('FAIL', $verdict['state']);
        $this->assertStringContainsString('access_logs', $verdict['detail']);
    }

    /**
     * The grant script answers table by table, so a table that arrived with a
     * later migration has no DML at all until it runs again — the one failure of
     * this design the operator has to be told about, because it looks like an
     * application bug and not a provisioning step.
     */
    public function testATableThatArrivedAfterTheGrantsWereAppliedIsNamed(): void
    {
        $verdict = AuditGrants::judge(
            [],
            [
                'audit_entries' => ['SELECT', 'INSERT'],
                'access_logs' => ['SELECT', 'INSERT'],
                'users' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            ],
            ['audit_entries', 'access_logs', 'users', 'attachments'],
        );

        $this->assertSame('FAIL', $verdict['state']);
        $this->assertStringContainsString('attachments', $verdict['detail']);
        $this->assertStringContainsString('app-grants.sql', $verdict['detail']);
    }

    public function testPrivilegeNamesAreReadWhicheverWayTheServerSpellsThem(): void
    {
        $verdict = AuditGrants::judge(
            ['insert'],
            ['audit_entries' => ['select', 'insert'], 'access_logs' => ['select', 'insert']],
            self::TRAIL,
        );

        $this->assertSame('FAIL', $verdict['state']);
    }
}
