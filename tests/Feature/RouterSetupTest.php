<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterAccess;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\MikroTik\MikroTikGateway;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

/** Running the system before a MikroTik is connected, and catching up once it is. */
class RouterSetupTest extends TestCase
{
    use RefreshDatabase;

    private FakeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->router = new FakeRouter;
        $this->app->instance(RouterGateway::class, $this->router);
    }

    public function test_router_counts_as_set_up_only_with_address_and_login(): void
    {
        $this->assertFalse((new MikroTikGateway(['host' => '192.168.88.1', 'username' => 'billing', 'password' => '']))->isConfigured());
        $this->assertFalse((new MikroTikGateway(['host' => '', 'username' => 'billing', 'password' => 'secret']))->isConfigured());
        $this->assertTrue((new MikroTikGateway(['host' => '192.168.88.1', 'username' => 'billing', 'password' => 'secret']))->isConfigured());
    }

    public function test_cut_off_without_a_router_is_recorded_and_skipped_not_retried(): void
    {
        $this->router->configured = false;
        $sub = Subscription::factory()->create();

        app(Billing::class)->suspend($sub);

        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame([], $this->router->calls, 'no router calls were attempted');
        $this->assertDatabaseHas('router_logs', ['subscription_id' => $sub->id, 'action' => 'disable', 'success' => false]);
        $this->assertStringContainsString('Router not set up yet', $sub->routerLogs()->value('message'));
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_catch_up_sync_creates_missing_accounts_and_applies_each_status(): void
    {
        $plan = Plan::factory()->create(['mikrotik_profile' => '50M']);
        $active = Subscription::factory()->for($plan)->create(['username' => 'juan01', 'password' => 'pppoe-secret']);
        $suspended = Subscription::factory()->for($plan)->create(['username' => 'ana02', 'status' => Subscription::SUSPENDED]);
        $existing = Subscription::factory()->for($plan)->create(['username' => 'old03']);
        $this->router->addAccount('old03', '50M');

        $this->artisan('mikrotik:sync', ['--now' => true, '--create-missing' => true])->assertSuccessful();

        $this->assertSame(['name' => 'juan01', 'profile' => '50M', 'disabled' => 'false'], array_intersect_key($this->router->accounts['juan01'], array_flip(['name', 'profile', 'disabled'])));
        $this->assertSame('pppoe-secret', $this->router->passwords['juan01']);
        $this->assertSame('true', $this->router->accounts['ana02']['disabled']);
        $this->assertSame('false', $this->router->accounts['old03']['disabled']);
        $this->assertSame(3, count($this->router->accounts));
    }

    public function test_plain_sync_still_reports_missing_accounts_instead_of_creating_them(): void
    {
        $sub = Subscription::factory()->create(['username' => 'ghost']);

        (new SyncRouterAccess($sub))->handle($this->router);

        $this->assertArrayNotHasKey('ghost', $this->router->accounts);
        $this->assertDatabaseHas('router_logs', ['subscription_id' => $sub->id, 'success' => false, 'message' => 'No pppoe account named "ghost" on the router.']);
    }

    public function test_adding_a_client_without_a_router_explains_when_the_account_will_be_made(): void
    {
        $this->router->configured = false;
        $plan = Plan::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.clients.store'), [
            'name' => 'Juan', 'email' => 'juan@example.com', 'plan_id' => $plan->id, 'service_type' => 'pppoe',
            'username' => 'juan01', 'service_password' => 'secret12', 'next_due_date' => today()->toDateString(), 'create_on_router' => '1',
        ])->assertSessionHas('status', fn (string $status) => str_contains($status, "The router isn't set up yet"));

        $this->assertSame([], $this->router->calls);
    }

    public function test_router_test_button_reports_each_outcome(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->router->configured = false;
        $this->post(route('admin.router.test'))->assertSessionHas('error', fn (string $e) => str_contains($e, "The router isn't set up yet"));
        $this->get(route('admin.dashboard'))->assertSee('Router: not set up')->assertSee('No router connected yet.');

        $this->router->configured = true;
        $this->post(route('admin.router.test'))->assertSessionHas('status', fn (string $s) => str_contains($s, 'Connected to router "FakeRouter"'));
        $this->get(route('admin.dashboard'))->assertSee('Router: connected')->assertDontSee('No router connected yet.');

        $this->router->down = true;
        $this->post(route('admin.router.test'))->assertSessionHas('error', fn (string $e) => str_contains($e, "Couldn't connect to the router"));
        $this->get(route('admin.dashboard'))->assertSee('Router: unreachable');
    }

    public function test_router_commands_say_what_is_missing_before_a_router_is_set_up(): void
    {
        $this->app->instance(RouterGateway::class, new MikroTikGateway(['host' => '', 'username' => 'billing', 'password' => '']));
        config(['isp.mikrotik' => ['host' => '', 'username' => 'billing', 'password' => '', 'port' => 8728, 'ssl' => false]]);

        $this->artisan('mikrotik:test')
            ->expectsOutputToContain("The router isn't set up yet. Fill in MIKROTIK_HOST, MIKROTIK_PASSWORD, then restart the app.")
            ->assertFailed();

        $this->artisan('mikrotik:sync', ['--now' => true, '--create-missing' => true])
            ->expectsOutputToContain("The router isn't set up yet")
            ->assertSuccessful();
    }

    public function test_customers_cannot_test_the_router(): void
    {
        $this->actingAs(User::factory()->create())->post(route('admin.router.test'))->assertForbidden();
    }
}
