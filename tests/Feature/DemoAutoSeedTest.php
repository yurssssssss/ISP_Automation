<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\Demo;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Vercel demo builds and seeds its own SQLite file (PrepareDemoDatabase). */
class DemoAutoSeedTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = tempnam(sys_get_temp_dir(), 'demo-db-');
        unlink($this->databasePath);
        config(['database.connections.sqlite.database' => $this->databasePath]);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach (['', '.seeded', '.lock'] as $suffix) {
            @unlink($this->databasePath.$suffix);
        }

        parent::tearDown();
    }

    public function test_first_visit_creates_and_seeds_the_database(): void
    {
        config(['isp.demo' => true, 'isp.demo_auto_seed' => true]);

        $this->get('/login')->assertOk()->assertSee(Demo::ADMIN_EMAIL);

        $this->assertSame(12, Client::count());
        $this->post('/login', ['email' => Demo::ADMIN_EMAIL, 'password' => Demo::ADMIN_PASSWORD])->assertRedirect(route('admin.dashboard'));
    }

    public function test_visitor_changes_last_until_the_next_day(): void
    {
        config(['isp.demo' => true, 'isp.demo_auto_seed' => true]);
        $this->get('/login');
        Client::first()->update(['name' => 'Changed by a visitor']);

        $this->get('/login');
        $this->assertDatabaseHas('clients', ['name' => 'Changed by a visitor']);

        $this->travel(1)->days();
        $this->get('/login');
        $this->assertDatabaseMissing('clients', ['name' => 'Changed by a visitor']);
        $this->assertSame(12, Client::count());
    }

    public function test_nothing_happens_without_the_auto_seed_flag(): void
    {
        config(['isp.demo' => true, 'isp.demo_auto_seed' => false]);
        $this->withoutExceptionHandling();

        try {
            $this->get('/');
        } catch (\Throwable) {
            // No tables exist; all that matters is that nothing was created.
        }

        $this->assertFileDoesNotExist($this->databasePath);
    }
}
