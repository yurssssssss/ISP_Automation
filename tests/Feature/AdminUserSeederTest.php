<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_copy_gets_the_default_admin_when_no_password_is_set(): void
    {
        $this->app['env'] = 'local';
        config(['isp.admin' => ['email' => 'admin@isp.test', 'password' => null]]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@isp.test')->firstOrFail();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Admin#12345', $admin->password));
    }

    public function test_default_admin_can_sign_in_to_the_admin_dashboard(): void
    {
        $this->app['env'] = 'local';
        config(['isp.admin' => ['email' => 'admin@isp.test', 'password' => null]]);
        $this->seed(AdminUserSeeder::class);
        $this->app['env'] = 'testing';

        $this->post('/login', ['email' => 'admin@isp.test', 'password' => 'Admin#12345'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_password_from_the_environment_is_used_when_set(): void
    {
        config(['isp.admin' => ['email' => 'Boss@Example.com', 'password' => 'Chosen#Pass1']]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Chosen#Pass1', $admin->password));
    }

    public function test_live_server_without_a_password_gets_no_admin(): void
    {
        $this->app['env'] = 'production';
        config(['isp.admin' => ['email' => 'admin@isp.test', 'password' => null]]);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true])
            ->expectsOutputToContain('Set ADMIN_PASSWORD in .env to create the first admin.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_admin_keeps_the_password_they_chose(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@isp.test', 'password' => 'Changed#Later1']);
        config(['isp.admin' => ['email' => 'admin@isp.test', 'password' => 'Original#Pass1']]);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(Hash::check('Changed#Later1', $admin->fresh()->password));
        $this->assertDatabaseCount('users', 1);
    }
}
