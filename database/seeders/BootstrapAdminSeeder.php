<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\GeneratedPassword;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Package 0.3 / Phase 1 — the single account that can log in before the
 * directory exists.
 *
 * There is no default password. A 20-character one is generated on first run
 * and written to storage/app/private/bootstrap-admin.credential (outside the
 * web root, and outside git); the seeder prints only where to read it. The
 * account is created with must_change_password = 1, so the forced-change screen
 * (FSD-103) runs on the very first login and the seeded secret never survives it.
 *
 * Admin holds no business approval right (FSD §6.1), so this account cannot
 * approve anything even in the empty system.
 */
class BootstrapAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@npd-ppm.local';

    public function run(): void
    {
        $existing = User::where('email', self::EMAIL)->first();

        if ($existing !== null) {
            $this->command?->line('Bootstrap administrator already present — nothing seeded.');

            return;
        }

        $departmentId = DB::table('departments')->where('name', 'Packaging Development')->value('id');
        $password = $this->resolvePassword();

        $user = User::create([
            'name' => 'System Administrator',
            'email' => self::EMAIL,
            'password' => $password,
            'department_id' => $departmentId,
            'is_active' => true,
            'must_change_password' => true,
            'locale' => 'id',
        ]);

        $user->syncRoles(['admin']);

        $this->writeCredentialFile($password);

        $this->command?->info('Bootstrap administrator: '.self::EMAIL);
        $this->command?->line(
            'Temporary password written to storage/app/private/bootstrap-admin.credential — '.
            'read it once, log in, and the forced change will retire it.'
        );
    }

    private function resolvePassword(): string
    {
        $fromEnv = (string) env('BOOTSTRAP_ADMIN_PASSWORD', '');

        return $fromEnv !== '' ? $fromEnv : GeneratedPassword::forPolicy();
    }

    private function writeCredentialFile(string $password): void
    {
        $dir = storage_path('app/private');

        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        file_put_contents(
            $dir.'/bootstrap-admin.credential',
            "email: ".self::EMAIL."\npassword: {$password}\n".
            "created: ".now()->toIso8601String()."\n".
            "This file is git-ignored and private-disk only. Delete it after the first login.\n"
        );

        @chmod($dir.'/bootstrap-admin.credential', 0600);
    }
}
