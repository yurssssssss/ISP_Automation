<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

class ScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_screens_render(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $sub = Subscription::factory()->create(['next_due_date' => today()]);
        app(Billing::class)->issueInvoice($sub);
        app(Billing::class)->suspend($sub, notify: false);

        $this->actingAs($admin);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee($sub->username)->assertSee('Restore');
        $this->get(route('admin.clients.create'))->assertOk();
        $this->get(route('admin.plans.index'))->assertOk();
    }

    public function test_dashboard_counts_overdue_bills(): void
    {
        Queue::fake();
        $billing = app(Billing::class);
        $billing->issueInvoice(Subscription::factory()->create(['next_due_date' => today()->subDay()]));
        $billing->issueInvoice(Subscription::factory()->create(['next_due_date' => today()]));
        $paid = $billing->issueInvoice(Subscription::factory()->create(['next_due_date' => today()->subDays(5)]));
        $billing->recordPayment($paid, (float) $paid->amount, 'cash', 'manual', null, now(), null);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertViewHas('stats', fn (array $stats) => $stats['overdue'] === 1)
            ->assertSee('1 overdue bill');
    }

    public function test_cash_payment_reconnects_a_suspended_client(): void
    {
        Queue::fake();
        $sub = Subscription::factory()->create(['next_due_date' => today()->subDays(2)]);
        $invoice = app(Billing::class)->issueInvoice($sub);
        app(Billing::class)->suspend($sub, notify: false);

        // No receipt number sent at all.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.invoices.cash', $invoice), ['amount' => 999])
            ->assertSessionHas('status', "Payment of ₱999.00 recorded for {$invoice->number}.");

        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_client_cannot_open_admin(): void
    {
        $client = Client::factory()->withLogin()->create();

        $this->actingAs($client->user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_portal_renders_with_pay_button(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_abc']);
        $client = Client::factory()->withLogin()->create();
        $sub = Subscription::factory()->for($client)->create(['next_due_date' => today()->addDays(2)]);
        $invoice = app(Billing::class)->issueInvoice($sub);

        $this->actingAs($client->user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Pay ₱999.00', false);
    }

    public function test_admin_adds_a_client_and_router_account(): void
    {
        $router = new FakeRouter;
        $this->app->instance(RouterGateway::class, $router);
        $plan = Plan::factory()->create(['mikrotik_profile' => '50M']);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.clients.store'), [
            'name' => 'Juan Dela Cruz',
            'email' => 'Juan@Example.com',
            'plan_id' => $plan->id,
            'service_type' => 'pppoe',
            'username' => 'juan01',
            'service_password' => 'pppsecret',
            'next_due_date' => today()->addMonth()->toDateString(),
            'create_on_router' => '1',
        ])->assertRedirect(route('admin.dashboard'));

        $client = Client::where('email', 'juan@example.com')->firstOrFail();
        $this->assertSame('ACC-'.str_pad((string) $client->id, 5, '0', STR_PAD_LEFT), $client->account_no);
        $this->assertSame('client', $client->user->role);
        $this->assertNull($client->user->email_verified_at);
        $this->assertSame('50M', $router->accounts['juan01']['profile']);
        // Stored encrypted, read back decrypted.
        $this->assertNotSame('pppsecret', \DB::table('subscriptions')->value('password'));
        $this->assertSame('pppsecret', $client->subscriptions->first()->password);
    }
}
