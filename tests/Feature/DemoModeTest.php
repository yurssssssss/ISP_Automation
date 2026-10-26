<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\MikroTik\MikroTikGateway;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The showcase copy (e.g. on Vercel): sample data, banner, demo sign-in, no real emails or payments. */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_refuses_outside_demo_mode(): void
    {
        config(['isp.demo' => false]);

        $this->artisan('demo:seed')->expectsOutputToContain('Refusing to run')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_seed_creates_customers_in_every_state(): void
    {
        config(['isp.demo' => true]);

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(12, Client::count());
        $this->assertSame(9, Subscription::where('status', Subscription::ACTIVE)->count());
        $this->assertSame(2, Subscription::where('status', Subscription::SUSPENDED)->count());
        $this->assertSame(1, Subscription::where('status', Subscription::CANCELLED)->count());
        $this->assertTrue(Subscription::where('auto_suspend', false)->exists(), 'a manual cut-off customer');
        $this->assertTrue(Subscription::whereNotNull('suspension_hold_until')->exists(), 'a kept-on customer');

        $this->post('/login', ['email' => Demo::ADMIN_EMAIL, 'password' => Demo::ADMIN_PASSWORD])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Juan Dela Cruz');
    }

    public function test_demo_customer_can_sign_in_and_see_their_bills(): void
    {
        config(['isp.demo' => true]);
        $this->artisan('demo:seed');

        $this->post('/login', ['email' => Demo::CLIENT_EMAIL, 'password' => Demo::CLIENT_PASSWORD])->assertRedirect(route('portal.dashboard'));
        $this->get(route('portal.dashboard'))->assertOk()->assertSee('Juan Dela Cruz')->assertSee('Connected');
    }

    public function test_running_the_seed_twice_does_not_duplicate(): void
    {
        config(['isp.demo' => true]);
        $this->artisan('demo:seed');

        $this->artisan('demo:seed')->expectsOutputToContain('already there');
        $this->assertSame(12, Client::count());
    }

    public function test_fresh_seed_undoes_visitor_changes(): void
    {
        config(['isp.demo' => true]);
        $this->artisan('demo:seed');
        Client::first()->update(['name' => 'Changed by a visitor']);

        $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();

        $this->assertSame(12, Client::count());
        $this->assertDatabaseMissing('clients', ['name' => 'Changed by a visitor']);
        $this->assertDatabaseHas('users', ['email' => Demo::ADMIN_EMAIL]);
    }

    public function test_banner_and_demo_accounts_show_only_in_demo_mode(): void
    {
        config(['isp.demo' => false]);
        $this->get('/login')->assertDontSee('Demo version')->assertDontSee(Demo::ADMIN_PASSWORD);

        config(['isp.demo' => true]);
        $this->get('/login')->assertSee('Demo version')->assertSee(Demo::ADMIN_EMAIL)->assertSee(Demo::ADMIN_PASSWORD);
        $this->get('/')->assertSee('Demo version');
    }

    public function test_dashboard_explains_the_demo_instead_of_warning(): void
    {
        config(['isp.demo' => true]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertSee('Scheduler: off (demo)')
            ->assertSee('Click <strong>Run checks now</strong>', false)
            ->assertDontSee("The scheduler isn't running", false);
    }

    public function test_demo_mode_never_sends_real_email_takes_payments_or_touches_a_router(): void
    {
        config([
            'isp.demo' => true, 'mail.default' => 'smtp', 'services.paymongo.secret_key' => 'sk_live_real',
            'isp.mikrotik.host' => '192.168.88.1', 'isp.mikrotik.password' => 'real-router-password',
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('log', config('mail.default'));
        $this->assertNull(config('services.paymongo.secret_key'));
        $this->assertFalse(MikroTikGateway::fromConfig()->isConfigured());
    }
}
