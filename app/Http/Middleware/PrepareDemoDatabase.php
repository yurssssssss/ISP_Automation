<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Vercel demo has no database server: each server instance keeps its
 * own SQLite file in /tmp. The first request of the day (or of a fresh
 * instance) creates the tables and seeds the sample data, so due dates
 * stay current and visitors' changes are undone daily.
 *
 * Only runs with DEMO_AUTO_SEED=true (set in vercel.json) on a SQLite file,
 * so it can never wipe a real database.
 */
class PrepareDemoDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::autoSeeds() && config('database.default') === 'sqlite') {
            $this->prepare((string) config('database.connections.sqlite.database'));
        }

        return $next($request);
    }

    private function prepare(string $path): void
    {
        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory')) {
            return;
        }

        $marker = $path.'.seeded';
        $today = today()->toDateString();

        if (@file_get_contents($marker) === $today) {
            return;
        }

        $lock = fopen($path.'.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            // Another request may have seeded while this one waited.
            if (@file_get_contents($marker) === $today) {
                return;
            }

            if (! file_exists($path)) {
                touch($path);
            }

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('demo:seed', ['--fresh' => true]);
            file_put_contents($marker, $today);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
