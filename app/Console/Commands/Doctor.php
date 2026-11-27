<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit\AuditGrants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TSD-PDS-003 §14.1, risk R-02 — "why is this environment not working", in one
 * command.
 *
 * Composer and MySQL are not on PATH by default on this host and Laragon's
 * services start on demand, so a silent failure here would otherwise look like
 * an application bug. Every check reports the observed value, not an assumed
 * one, and the exit code is non-zero when anything is FAIL.
 */
class Doctor extends Command
{
    protected $signature = 'pds:doctor {--json : machine-readable output}';

    protected $description = 'Report every prerequisite the application depends on';

    /**
     * PHP extensions the code uses. Missing ones are named with the file that
     * needs them, because "extension missing" alone never tells anyone what to
     * turn on.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'pdo_mysql' => 'all database access',
        'mbstring' => 'text handling for Indonesian labels',
        'openssl' => 'session encryption, signed portal tokens',
        'zip' => 'XLSX write (PhpSpreadsheet)',
        'gd' => 'image evidence checks and PDF rendering',
        'curl' => 'outbound mail and integrations',
        'fileinfo' => 'upload MIME sniffing (config/upload.php)',
        'intl' => 'number and date formatting',
        'bcmath' => 'exact money maths in quotations',
        'dom' => 'Dompdf',
    ];

    /** @var list<array{label: string, state: string, detail: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkPhp();
        $this->checkExtensions();
        $this->checkDatabase();
        $this->checkMigrations();
        $this->checkMatrixSeed();
        $this->checkCalendarSeed();
        $this->checkAuditImmutability();
        $this->checkStorage();
        $this->checkDrivers();
        $this->checkTimezone();
        $this->checkBuiltAssets();
        $this->checkMail();

        if ($this->option('json')) {
            $this->line(json_encode($this->rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->worstState() === 'FAIL' ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['Check', 'State', 'Observed'],
            array_map(
                static fn (array $r): array => [$r['label'], $r['state'], $r['detail']],
                $this->rows
            ),
            'default'
        );

        $fails = count(array_filter($this->rows, static fn (array $r): bool => $r['state'] === 'FAIL'));
        $warns = count(array_filter($this->rows, static fn (array $r): bool => $r['state'] === 'WARN'));

        $this->line(sprintf('%d checks, %d fail, %d warn', count($this->rows), $fails, $warns));

        return $fails > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function record(string $label, string $state, string $detail): void
    {
        $this->rows[] = ['label' => $label, 'state' => $state, 'detail' => $detail];
    }

    private function worstState(): string
    {
        foreach ($this->rows as $row) {
            if ($row['state'] === 'FAIL') {
                return 'FAIL';
            }
        }

        return 'OK';
    }

    private function checkPhp(): void
    {
        $version = PHP_VERSION;
        $state = version_compare($version, '8.3.0', '>=') ? 'OK' : 'FAIL';

        $this->record('PHP', $state, $version.' ('.(PHP_BINARY).')');
    }

    private function checkExtensions(): void
    {
        $missing = [];

        foreach (self::EXTENSIONS as $ext => $usedFor) {
            if (! extension_loaded($ext)) {
                $missing[] = $ext.' ('.$usedFor.')';
            }
        }

        $this->record(
            'PHP extensions',
            $missing === [] ? 'OK' : 'FAIL',
            $missing === [] ? 'all '.count(self::EXTENSIONS).' required extensions loaded' : 'missing: '."\n".implode("\n", $missing)
        );
    }

    private function checkDatabase(): void
    {
        $expected = [
            'driver' => 'mysql',
            'host' => (string) config('database.connections.mysql.host'),
            'port' => (string) config('database.connections.mysql.port'),
            'database' => (string) config('database.connections.mysql.database'),
            'user' => (string) config('database.connections.mysql.username'),
        ];

        try {
            $row = DB::selectOne('select database() as db, current_user() as usr, version() as ver');

            $this->record(
                'Database connection',
                $row?->db === $expected['database'] ? 'OK' : 'WARN',
                "target {$expected['database']} on {$expected['host']}:{$expected['port']} as {$expected['user']} — connected to `{$row?->db}` as `{$row?->usr}` (MySQL {$row?->ver})"
            );
        } catch (Throwable $e) {
            $this->record('Database connection', 'FAIL', $e->getMessage());

            return;
        }

        $charset = DB::selectOne("select @@character_set_database as cs, @@collation_database as col");

        $this->record(
            'Database charset',
            ($charset?->cs === 'utf8mb4' && str_starts_with((string) $charset?->col, 'utf8mb4_')) ? 'OK' : 'WARN',
            ($charset?->cs ?? '?').' / '.($charset?->col ?? '?').' (TSD §3.1 requires utf8mb4_0900_ai_ci)'
        );
    }

    private function checkMigrations(): void
    {
        try {
            $applied = DB::table('migrations')->pluck('migration')->all();

            $files = glob(database_path('migrations/*.php')) ?: [];
            $names = array_map(static fn (string $p): string => basename($p, '.php'), $files);
            $pending = array_values(array_diff($names, $applied));

            $this->record(
                'Migrations',
                $pending === [] ? 'OK' : 'WARN',
                count($applied).' applied'.($pending === [] ? '' : ', pending: '.implode(', ', $pending))
            );
        } catch (Throwable $e) {
            $this->record('Migrations', 'FAIL', $e->getMessage());
        }
    }

    private function checkMatrixSeed(): void
    {
        try {
            $source = require database_path('seeders/data/permission-matrix.php');
            $expectedRoles = count($source['roles']);
            $expectedFeatures = count($source['features']);
            $expectedGrants = $expectedRoles * $expectedFeatures;

            $roles = DB::table('roles')->count();
            $features = DB::table('features')->count();
            $grants = DB::table('feature_grants')->count();

            $ok = $roles >= $expectedRoles && $features === $expectedFeatures && $grants >= $expectedGrants;

            $this->record(
                'Permission matrix',
                $ok ? 'OK' : 'WARN',
                "{$roles} roles, {$features} features, {$grants} grant rows (FSD §6 expects {$expectedRoles} x {$expectedFeatures} = {$expectedGrants})"
            );
        } catch (Throwable $e) {
            $this->record('Permission matrix', 'FAIL', $e->getMessage());
        }
    }

    private function checkCalendarSeed(): void
    {
        try {
            $rows = DB::table('working_day_calendar')->count();
            $working = DB::table('working_day_calendar')->where('is_working_day', true)->count();
            $holidays = DB::table('working_day_calendar')->whereNotNull('label')->count();

            $this->record(
                'Working-day calendar',
                $rows > 0 ? 'OK' : 'WARN',
                "{$rows} dated rows, {$working} working days, {$holidays} labelled holidays"
            );
        } catch (Throwable $e) {
            $this->record('Working-day calendar', 'FAIL', $e->getMessage());
        }
    }

    /**
     * TSD-1102 — the app account must not be able to rewrite the audit trail.
     *
     * The grant lives with the DBA, so all the application can do is report what
     * the server has been told; App\Support\Audit\AuditGrants holds both the
     * reading and the rule it is read against, including the schema-level grant
     * that makes a per-table answer beside the point.
     */
    private function checkAuditImmutability(): void
    {
        $present = array_values(array_filter(
            AuditGrants::TRAIL_TABLES,
            static fn (string $table): bool => DB::select("show tables like '{$table}'") !== []
        ));

        if ($present === []) {
            $this->record('Audit grants', 'WARN', 'audit tables not created yet (Phase 1); re-run after migrate');

            return;
        }

        try {
            $verdict = AuditGrants::inspect();

            $this->record('Audit grants', $verdict['state'], $verdict['detail']);
        } catch (Throwable $e) {
            $this->record('Audit grants', 'WARN', 'could not read the grants: '.$e->getMessage());
        }
    }

    private function checkStorage(): void
    {
        $paths = [
            storage_path('app/private'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        $bad = array_values(array_filter($paths, static fn (string $p): bool => ! is_writable($p)));

        $this->record(
            'Writable storage',
            $bad === [] ? 'OK' : 'FAIL',
            $bad === [] ? count($paths).' paths writable (private disk is outside the web root)' : 'not writable: '.implode(', ', $bad)
        );
    }

    private function checkDrivers(): void
    {
        $queue = config('queue.default');
        $cache = config('cache.default');
        $session = config('session.driver');

        // ADR-05: no Redis on this host. If someone sets it anyway, say so
        // before the first queued job fails with a connection error.
        $redis = in_array($queue, ['redis'], true) || in_array($cache, ['redis'], true);

        $this->record(
            'Drivers',
            $redis ? 'WARN' : 'OK',
            "queue={$queue} cache={$cache} session={$session}".($redis ? ' — Redis is not installed on this host (ADR-05)' : '')
        );
    }

    private function checkTimezone(): void
    {
        $tz = config('app.timezone');
        $now = now()->format('Y-m-d H:i T');

        $this->record(
            'Timezone',
            $tz === 'Asia/Jakarta' ? 'OK' : 'WARN',
            "app.timezone={$tz} — clock reads {$now} (TSD §14.2: all SLA maths and digests are WIB)"
        );
    }

    private function checkBuiltAssets(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->record('Front-end build', 'WARN', 'public/build/manifest.json absent — run npm run build (or npm run dev)');

            return;
        }

        $built = json_decode((string) file_get_contents($manifest), true);
        $hasEntry = is_array($built) && (($built['resources/js/app.jsx'] ?? null) !== null);

        // Say what the check can actually see. "OK" on the strength of the entry
        // point alone reads like a promise about the screens, and it is not one:
        // the manifest lists what was built, so a route that renders
        // Inertia::render('Admin/Users') before that file exists passes this
        // check and then fails in the browser with a ViteException. Counting the
        // page components turns the row into something an operator can compare
        // against the screens they were promised; the assertion that every route
        // has one is the Phase 1.6 inventory test's job, not this one's.
        $pages = is_array($built)
            ? array_values(array_filter(
                array_keys($built),
                static fn (string $key): bool => str_starts_with($key, 'resources/js/Pages/')
                    && ! str_ends_with($key, '.css')
            ))
            : [];

        $this->record(
            'Front-end build',
            $hasEntry ? 'OK' : 'WARN',
            $hasEntry
                ? 'entry point plus '.count($pages).' page components built — a route whose screen has not been written yet is caught by the Phase 1.6 inventory test, not by this row'
                : 'manifest exists but has no resources/js/app.jsx entry'
        );
    }

    private function checkMail(): void
    {
        $mailer = config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $port = (string) config('mail.mailers.smtp.port');

        if ($mailer !== 'smtp') {
            $this->record('Mail', 'WARN', "default mailer is {$mailer}; nothing will leave the system by email");

            return;
        }

        $link = @fsockopen($host, (int) $port, $errno, $errstr, 1.5);

        if ($link === false) {
            $this->record('Mail', 'WARN', "smtp {$host}:{$port} not accepting connections ({$errstr}) — start Mailpit, or N-* notifications will queue and retry");

            return;
        }

        fclose($link);
        $this->record('Mail', 'OK', "smtp {$host}:{$port} reachable");
    }
}
