<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Automation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The dashboard's view of the nightly database backups (docker/backup.sh). */
class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['isp.backup_path' => $this->dir]);
        $this->actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function backupFile(string $name, int $hoursAgo, int $bytes = 2 * 1048576): void
    {
        File::ensureDirectoryExists($this->dir);
        file_put_contents("{$this->dir}/{$name}", str_repeat('x', $bytes));
        touch("{$this->dir}/{$name}", now()->subHours($hoursAgo)->getTimestamp());
    }

    public function test_nothing_is_shown_where_no_backup_service_runs(): void
    {
        $this->assertNull(Automation::status()['backup']);

        $this->get(route('admin.dashboard'))->assertDontSee('Database backup')->assertDontSee('No database backup');
    }

    public function test_latest_backup_is_shown(): void
    {
        $this->backupFile('isp-2026-10-19_0200.sql.gz', 30);
        $this->backupFile('isp-2026-10-20_0200.sql.gz', 6);

        $backup = Automation::status()['backup'];
        $this->assertSame('isp-2026-10-20_0200.sql.gz', $backup['latest']);
        $this->assertSame(2, $backup['count']);
        $this->assertFalse($backup['stale']);

        $this->get(route('admin.dashboard'))->assertSee('Database backup:')->assertSee('2.0 MB')->assertSee('2 copies kept');
    }

    public function test_backup_time_is_shown_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 20)->setTime(20, 30));
        File::ensureDirectoryExists($this->dir);
        file_put_contents("{$this->dir}/isp-2026-10-20_2015.sql.gz", 'x');
        touch("{$this->dir}/isp-2026-10-20_2015.sql.gz", now('Asia/Manila')->setTime(20, 15)->getTimestamp());

        $this->assertSame('8:15 PM', Automation::status()['backup']['at']->format('g:i A'));
    }

    public function test_warns_when_the_last_backup_is_too_old(): void
    {
        $this->backupFile('isp-2026-10-18_0200.sql.gz', Automation::BACKUP_STALE_AFTER_HOURS + 2);

        $this->assertTrue(Automation::status()['backup']['stale']);
        $this->get(route('admin.dashboard'))->assertSee('No database backup since');
    }

    public function test_warns_when_the_backup_folder_is_empty(): void
    {
        File::ensureDirectoryExists($this->dir);
        file_put_contents("{$this->dir}/.isp-unfinished.sql.gz.part", 'partial');

        $this->get(route('admin.dashboard'))->assertSee('No database backup yet.');
    }
}
