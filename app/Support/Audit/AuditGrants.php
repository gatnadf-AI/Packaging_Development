<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Facades\DB;

/**
 * The reading half of TSD-1102, for `php artisan pds:doctor`.
 *
 * The rule "even a bug that issues an UPDATE fails at the database" is a property
 * of the grants, and the application is the subject of those grants: it cannot
 * test itself by writing, only report what the server has been told. So this class
 * holds the reading and the judgement, and nothing else — the writing is proven
 * against the deployed schema by the verdict below rather than by a test that
 * RefreshDatabase would rebuild out from under itself.
 *
 * The judgement is a method on its own because the state it exists to catch is
 * one that reads as success: the application account holds DML at schema level,
 * a schema-level grant cannot be subtracted from at table level, and so a check
 * that only looks at the per-table rows says "no UPDATE granted" about a database
 * that takes it from above. That is what the first version of
 * database/provision/app-grants.sql did, and what the doctor could not see.
 */
final class AuditGrants
{
    /**
     * The two tables the trail is made of (FSD-907 and FSD-908): changes that were
     * made, and reads that were refused. Both are evidence, so both are append-only
     * for the same reason and granted the same two verbs.
     *
     * @var list<string>
     */
    public const TRAIL_TABLES = ['audit_entries', 'access_logs'];

    private const DML = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /**
     * The grants as the connected account sees them, judged.
     *
     * @return array{state: string, detail: string}
     */
    public static function inspect(): array
    {
        $tables = array_map(
            static fn (object $row): string => (string) $row->name,
            DB::select(<<<'SQL'
                select table_name as name from information_schema.tables
                 where table_schema = database() and table_type = 'BASE TABLE'
            SQL)
        );

        $schema = array_map(
            static fn (object $row): string => (string) $row->verb,
            DB::select(<<<'SQL'
                select privilege_type as verb from information_schema.schema_privileges
                 where table_schema = database()
                   and grantee = concat(quote(substring_index(current_user(), '@', 1)),
                                        '@',
                                        quote(substring_index(current_user(), '@', -1)))
            SQL)
        );

        $granted = [];

        foreach (DB::select(<<<'SQL'
                select table_name as name, privilege_type as verb from information_schema.table_privileges
                 where table_schema = database()
                   and grantee = concat(quote(substring_index(current_user(), '@', 1)),
                                        '@',
                                        quote(substring_index(current_user(), '@', -1)))
            SQL) as $row) {
            $granted[strtolower((string) $row->name)][] = (string) $row->verb;
        }

        return self::judge($schema, $granted, $tables);
    }

    /**
     * @param  list<string>  $schemaPrivileges  DML the connected account holds over the whole schema
     * @param  array<string, list<string>>  $tablePrivileges  table => the verbs granted on it alone
     * @param  list<string>  $baseTables  every table in the schema
     * @return array{state: string, detail: string}
     */
    public static function judge(array $schemaPrivileges, array $tablePrivileges, array $baseTables): array
    {
        $normalise = static fn (string $table): string => strtolower($table);

        $granted = [];

        foreach ($tablePrivileges as $table => $verbs) {
            $granted[$normalise((string) $table)] = self::verbs($verbs);
        }

        $held = self::verbs($schemaPrivileges);
        $fromAbove = array_values(array_intersect(self::DML, $held));

        if ($fromAbove !== []) {
            return [
                'state' => 'FAIL',
                'detail' => implode(', ', $fromAbove)
                    .' granted at schema level, which no table-level grant can take back'
                    .' — run database/provision/app-grants.sql as root',
            ];
        }

        $trail = array_values(array_filter(
            array_map($normalise, $baseTables),
            static fn (string $table): bool => in_array($table, self::TRAIL_TABLES, true)
        ));

        $rewritable = [];
        $unusable = [];

        foreach ($trail as $table) {
            $verbs = $granted[$table] ?? [];

            if (array_intersect(['UPDATE', 'DELETE'], $verbs) !== []) {
                $rewritable[] = $table.' ('.implode(', ', $verbs).')';

                continue;
            }

            $missing = array_values(array_diff(['SELECT', 'INSERT'], $verbs));

            if ($missing !== []) {
                $unusable[] = $table.' (no '.implode(', ', $missing).')';
            }
        }

        // The cost of granting table by table: a table a later migration creates
        // has no grant until the script runs again. It breaks the application
        // rather than the rule, which is why it is named table by table instead of
        // folded into the sentence above.
        $waiting = [];

        foreach (array_map($normalise, $baseTables) as $table) {
            if (in_array($table, self::TRAIL_TABLES, true)) {
                continue;
            }

            $missing = array_values(array_diff(self::DML, $granted[$table] ?? []));

            if ($missing !== []) {
                $waiting[] = $table.' (no '.implode(', ', $missing).')';
            }
        }

        $reasons = array_filter([
            $rewritable === [] ? '' : 'still writable at the database: '.implode('; ', $rewritable),
            $unusable === [] ? '' : 'trail the account cannot use: '.implode('; ', $unusable),
            $waiting === [] ? '' : 'tables with no grant of their own: '.implode('; ', $waiting),
        ]);

        if ($reasons !== []) {
            return [
                'state' => 'FAIL',
                'detail' => implode(' · ', $reasons).' — run database/provision/app-grants.sql as root',
            ];
        }

        return [
            'state' => 'OK',
            'detail' => implode(' and ', $trail).' granted SELECT and INSERT only, with no DML above them (TSD-1102)',
        ];
    }

    /**
     * @param  list<string>  $verbs
     * @return list<string>
     */
    private static function verbs(array $verbs): array
    {
        return array_values(array_unique(array_map(
            static fn (string $verb): string => strtoupper(trim($verb)),
            array_map(static fn($v): string => (string) $v, $verbs)
        )));
    }
}
