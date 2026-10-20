<?php

namespace App\Support;

use App\Services\MikroTik\RouterGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily billing automation: which tasks run when (routes/console.php
 * schedules them from TASKS), when each last ran, and whether the two
 * background processes it needs are alive —
 *
 *  - the scheduler (`schedule:work`, or cron + `schedule:run`), seen through
 *    a heartbeat it writes every minute;
 *  - the queue worker (`queue:work`), which pushes cut/reconnect changes to
 *    the router and sends the emails; seen through jobs left waiting.
 */
class Automation
{
    /**
     * Command => [label, daily time]. "Run now" on the dashboard runs the
     * ones marked runNow, in this order.
     *
     * @var array<string, array{label: string, at: string, runNow: bool}>
     */
    public const TASKS = [
        'billing:issue-invoices' => ['label' => 'Create and email new bills', 'at' => '00:10', 'runNow' => true],
        'billing:send-reminders' => ['label' => 'Send due-date reminders', 'at' => '08:00', 'runNow' => true],
        'billing:suspend-overdue' => ['label' => 'Cut accounts unpaid after the due date', 'at' => '00:00', 'runNow' => true],
        'mikrotik:sync' => ['label' => 'Re-check every account on the router', 'at' => '01:00', 'runNow' => false],
    ];

    /** A job waiting longer than this means no worker is picking jobs up. */
    public const STUCK_AFTER_SECONDS = 120;

    /** No heartbeat for this long means the scheduler isn't running. */
    public const SCHEDULER_SILENT_AFTER_SECONDS = 180;

    /** Backups run daily; older than this means the backup service stopped. */
    public const BACKUP_STALE_AFTER_HOURS = 26;

    private const HEARTBEAT_KEY = 'automation:heartbeat';

    public static function record(string $command, string $summary): void
    {
        Cache::forever('automation:task:'.$command, ['at' => now()->toIso8601String(), 'summary' => $summary]);
    }

    /** Result of the dashboard's "Test router" button. */
    public static function recordRouterCheck(bool $ok, string $message): void
    {
        Cache::forever('automation:router-check', ['ok' => $ok, 'message' => $message, 'at' => now()->toIso8601String()]);
    }

    public static function heartbeat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    /** @return list<string> */
    public static function runNowCommands(): array
    {
        return array_keys(array_filter(self::TASKS, fn (array $task): bool => $task['runNow']));
    }

    /**
     * @return array{
     *     scheduler: array{running: bool, lastSeen: ?CarbonImmutable},
     *     queue: array{mode: string, pending: int, stuck: bool, failed: int},
     *     router: array{configured: bool, host: ?string, lastCheck: ?array{ok: bool, message: string, at: CarbonImmutable}},
     *     backup: ?array{latest: ?string, at: ?CarbonImmutable, bytes: int, count: int, stale: bool},
     *     tasks: list<array{command: string, label: string, at: string, lastRun: ?CarbonImmutable, summary: ?string}>
     * }
     */
    public static function status(): array
    {
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $routerCheck = Cache::get('automation:router-check');

        $tasks = [];
        foreach (self::TASKS as $command => $task) {
            $last = Cache::get('automation:task:'.$command);
            $tasks[] = [
                'command' => $command,
                'label' => $task['label'],
                'at' => $task['at'],
                'lastRun' => $last ? CarbonImmutable::parse($last['at']) : null,
                'summary' => $last['summary'] ?? null,
            ];
        }

        return [
            'scheduler' => [
                'running' => $heartbeat && now()->getTimestamp() - $heartbeat < self::SCHEDULER_SILENT_AFTER_SECONDS,
                'lastSeen' => $heartbeat ? CarbonImmutable::createFromTimestamp($heartbeat, config('app.timezone')) : null,
            ],
            'queue' => self::queueStatus(),
            'router' => [
                'configured' => app(RouterGateway::class)->isConfigured(),
                'host' => config('isp.mikrotik.host'),
                'lastCheck' => $routerCheck ? ['ok' => $routerCheck['ok'], 'message' => $routerCheck['message'], 'at' => CarbonImmutable::parse($routerCheck['at'])] : null,
            ],
            'backup' => self::backupStatus(),
            'tasks' => $tasks,
        ];
    }

    /**
     * The newest file in the backup folder; null when there's no backup folder
     * at all (local development, where no backup service runs).
     *
     * @return ?array{latest: ?string, at: ?CarbonImmutable, bytes: int, count: int, stale: bool}
     */
    private static function backupStatus(): ?array
    {
        $dir = config('isp.backup_path');
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'isp-*.sql.gz') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $files[0] ?? null;
        $at = $latest ? CarbonImmutable::createFromTimestamp(filemtime($latest), config('app.timezone')) : null;

        return [
            'latest' => $latest ? basename($latest) : null,
            'at' => $at,
            'bytes' => $latest ? (int) filesize($latest) : 0,
            'count' => count($files),
            'stale' => ! $at || $at->lt(now()->subHours(self::BACKUP_STALE_AFTER_HOURS)),
        ];
    }

    /** @return array{mode: string, pending: int, stuck: bool, failed: int} */
    private static function queueStatus(): array
    {
        $connection = config('queue.default');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            // sync: jobs run inside the request — nothing can be left waiting.
            return ['mode' => $connection, 'pending' => 0, 'stuck' => false, 'failed' => $failed];
        }

        $jobs = DB::table(config("queue.connections.{$connection}.table", 'jobs'));
        $oldest = (clone $jobs)->min('available_at');

        return [
            'mode' => 'database',
            'pending' => $jobs->count(),
            'stuck' => $oldest !== null && now()->getTimestamp() - (int) $oldest > self::STUCK_AFTER_SECONDS,
            'failed' => $failed,
        ];
    }
}
