<?php

namespace App\Support;

/**
 * Demo mode (DEMO_MODE=true): a showcase copy with sample data, for example
 * on Vercel. The router connection and nightly automation are off, emails go
 * to the log only, and the sign-in page shows these two accounts so a
 * visitor can look around. `php artisan demo:seed` creates them.
 */
class Demo
{
    public const ADMIN_EMAIL = 'admin@demo.test';

    public const ADMIN_PASSWORD = 'Demo#Admin2026';

    public const CLIENT_EMAIL = 'juan@demo.test';

    public const CLIENT_PASSWORD = 'Demo#Client2026';

    public static function enabled(): bool
    {
        return (bool) config('isp.demo');
    }

    /** The Vercel copy builds and seeds its own SQLite database (PrepareDemoDatabase). */
    public static function autoSeeds(): bool
    {
        return self::enabled() && (bool) config('isp.demo_auto_seed');
    }
}
