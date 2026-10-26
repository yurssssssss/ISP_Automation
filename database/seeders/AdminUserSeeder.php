<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The first admin login (config/isp.php → admin). Only creates it: an admin
 * that already exists is left alone, so re-seeding (Docker seeds on every
 * start) never undoes a password the admin has since changed.
 *
 * With ADMIN_PASSWORD empty, a local copy gets LOCAL_DEFAULT_PASSWORD; any
 * other environment refuses, so a live server never has a guessable admin.
 *
 *   php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    /** Meets the password rules in AppServiceProvider. */
    public const LOCAL_DEFAULT_PASSWORD = 'Admin#12345';

    public function run(): void
    {
        $email = Str::lower(trim((string) config('isp.admin.email'))) ?: 'admin@isp.test';

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Admin {$email} already exists — left unchanged.");

            return;
        }

        $password = config('isp.admin.password');
        $usesDefault = blank($password);

        if ($usesDefault && ! app()->isLocal()) {
            $this->command?->error('Set ADMIN_PASSWORD in .env to create the first admin.');

            return;
        }

        // A live system's admin password must pass the same rules as everyone else's.
        if (! $usesDefault && ! app()->isLocal()
            && Validator::make(['password' => $password], ['password' => Password::defaults()])->fails()) {
            $this->command?->error('ADMIN_PASSWORD is too weak: use 8+ characters with upper- and lowercase letters, a number and a symbol.');

            return;
        }

        User::forceCreate([
            'name' => 'Administrator',
            'email' => $email,
            'password' => $usesDefault ? self::LOCAL_DEFAULT_PASSWORD : $password,
            'role' => User::ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command?->info($usesDefault
            ? "Admin created: {$email} / ".self::LOCAL_DEFAULT_PASSWORD.' (local default — change it with "Forgot password").'
            : "Admin created: {$email} (password from ADMIN_PASSWORD).");
    }
}
