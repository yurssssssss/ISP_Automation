<?php

namespace Tests\Feature;

use App\Mail\ClientWelcome;
use App\Mail\InvoiceReminder;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

/** Editing, cancelling and resuming a customer; portal sign-in; the welcome email. */
class ClientAccountTest extends TestCase
{
    use RefreshDatabase;

    private FakeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->router = new FakeRouter;
        $this->app->instance(RouterGateway::class, $this->router);
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(9, 0));
        $this->actingAs(User::factory()->admin()->create());
    }

    private function customer(array $attributes = []): Subscription
    {
        $client = Client::factory()->withLogin()->create();
        $sub = Subscription::factory()->for($client)->create(['next_due_date' => '2026-11-15'] + $attributes);
        $this->router->addAccount($sub->username, $sub->plan->mikrotik_profile);

        return $sub;
    }

    private function form(Subscription $sub, array $overrides = []): array
    {
        return $overrides + [
            'name' => $sub->client->name,
            'email' => $sub->client->user->email,
            'phone' => $sub->client->phone,
            'address' => $sub->client->address,
            'plan_id' => $sub->plan_id,
            'next_due_date' => $sub->next_due_date->toDateString(),
            'auto_suspend' => 1,
        ];
    }

    public function test_details_are_updated_and_a_new_email_must_be_confirmed(): void
    {
        $sub = $this->customer();

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['name' => 'Juan Dela Cruz', 'email' => 'NEW@Example.com', 'phone' => '0917']))
            ->assertRedirect(route('admin.clients.edit', $sub))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, "they'll confirm it with a code"));

        $user = $sub->client->user->fresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('Juan Dela Cruz', $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('0917', $sub->client->fresh()->phone);
        $this->assertSame('new@example.com', $sub->client->fresh()->email);
    }

    public function test_email_already_used_by_someone_else_is_refused(): void
    {
        $sub = $this->customer();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['email' => 'taken@example.com']))
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);
    }

    public function test_plan_change_puts_the_new_speed_profile_on_the_router(): void
    {
        $sub = $this->customer();
        $faster = Plan::factory()->create(['mikrotik_profile' => '50MBPS']);

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['plan_id' => $faster->id]));

        $this->assertSame($faster->id, $sub->fresh()->plan_id);
        $this->assertSame('50MBPS', $this->router->accounts[$sub->username]['profile']);
        $this->assertArrayNotHasKey($sub->username, $this->router->active, 'reconnected so the new speed applies');
        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);
    }

    public function test_malformed_plan_id_is_rejected_not_saved(): void
    {
        // MySQL compares "4\n1" equal to 4, so only an integer rule stops it
        // from passing the exists check and then failing on save.
        $sub = $this->customer();

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['plan_id' => $sub->plan_id."\n1"]))
            ->assertSessionHasErrors('plan_id');
    }

    public function test_hidden_plan_can_be_kept_but_not_newly_chosen(): void
    {
        $sub = $this->customer();
        $sub->plan->update(['is_active' => false]);
        $otherHidden = Plan::factory()->create(['is_active' => false]);

        $this->put(route('admin.clients.update', $sub), $this->form($sub))->assertSessionHasNoErrors();
        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['plan_id' => $otherHidden->id]))->assertSessionHasErrors('plan_id');
    }

    public function test_moving_the_due_date_moves_the_unpaid_bill_for_this_cycle(): void
    {
        $sub = $this->customer(['next_due_date' => '2026-10-25']);
        $invoice = app(Billing::class)->issueInvoice($sub);
        $invoice->update(['reminded_at' => now()]);

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['next_due_date' => '2026-11-05']));

        $this->assertSame('2026-11-05', $sub->fresh()->next_due_date->toDateString());
        $invoice->refresh();
        $this->assertSame('2026-11-05', $invoice->due_date->toDateString());
        $this->assertSame('2026-12-04', $invoice->period_end->toDateString());
        $this->assertNull($invoice->reminded_at, 'a fresh reminder goes out before the new date');
    }

    public function test_due_date_cannot_be_moved_into_the_past(): void
    {
        $sub = $this->customer();

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['next_due_date' => '2026-10-01']))
            ->assertSessionHasErrors(['next_due_date' => 'Pick today or a later date.']);
    }

    public function test_new_pppoe_password_is_saved_and_set_on_the_router(): void
    {
        $sub = $this->customer();

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['service_password' => 'new-secret-9']))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'update it on the customer\'s own router too'));

        $this->assertSame('new-secret-9', $sub->fresh()->password);
        $this->assertSame('new-secret-9', $this->router->passwords[$sub->username]);
    }

    public function test_switching_to_manual_cut_off_clears_a_keep_on_date(): void
    {
        $sub = $this->customer(['suspension_hold_until' => '2026-10-25']);

        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['auto_suspend' => 0]));

        $this->assertFalse($sub->fresh()->auto_suspend);
        $this->assertNull($sub->fresh()->suspension_hold_until);
    }

    public function test_cancelling_cuts_the_router_and_stops_bills_and_reminders(): void
    {
        $sub = $this->customer(['next_due_date' => '2026-10-22']);
        $invoice = app(Billing::class)->issueInvoice($sub);

        $this->post(route('admin.clients.cancel', $sub))->assertSessionHas('status', fn (string $s) => str_contains($s, 'service cancelled'));

        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->assertSame('inactive', $sub->client->fresh()->status);
        $this->assertSame('true', $this->router->accounts[$sub->username]['disabled']);

        $this->artisan('billing:send-reminders');
        Mail::assertNotQueued(InvoiceReminder::class);
        $this->travelTo(now()->addMonths(2));
        $this->artisan('billing:issue-invoices');
        $this->assertSame(1, $sub->invoices()->count(), 'no new bills');
        $this->assertTrue($invoice->fresh()->isOpen(), 'the old bill is still owed');
    }

    public function test_cancelled_customers_are_listed_and_can_be_resumed(): void
    {
        $sub = $this->customer();
        app(Billing::class)->cancel($sub);

        $this->get(route('admin.dashboard'))->assertViewHas('subscriptions', fn ($subscriptions) => ! $subscriptions->contains('id', $sub->id));
        $this->get(route('admin.clients.index'))->assertSee($sub->username)->assertSee('Cancelled');

        $this->post(route('admin.clients.resume', $sub), ['next_due_date' => '2026-11-20'])->assertSessionHasNoErrors();

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('2026-11-20', $sub->next_due_date->toDateString());
        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);
    }

    public function test_portal_sign_in_can_be_turned_off_and_on(): void
    {
        $sub = $this->customer();
        $user = $sub->client->user;

        $this->post(route('admin.clients.portal', $sub), ['is_active' => 0]);
        $this->assertFalse($user->fresh()->is_active);
        $this->actingAs($user->fresh())->get(route('portal.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.clients.portal', $sub), ['is_active' => 1]);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_portal_shows_a_cancelled_service_as_cancelled(): void
    {
        $sub = $this->customer();
        app(Billing::class)->cancel($sub);

        $this->actingAs($sub->client->user)->get(route('portal.dashboard'))
            ->assertSee('Service cancelled')->assertDontSee('Connected');
    }

    public function test_customers_cannot_edit_or_cancel_anyone(): void
    {
        $sub = $this->customer();
        $this->actingAs($sub->client->user);

        $this->get(route('admin.clients.edit', $sub))->assertForbidden();
        $this->put(route('admin.clients.update', $sub), $this->form($sub, ['name' => 'Hacked']))->assertForbidden();
        $this->post(route('admin.clients.cancel', $sub))->assertForbidden();
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_edit_page_escapes_the_customer_name(): void
    {
        $sub = $this->customer();
        $sub->client->update(['name' => '<script>alert(1)</script>']);

        $this->get(route('admin.clients.edit', $sub))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_adding_a_client_sends_a_welcome_email_without_any_password(): void
    {
        $plan = Plan::factory()->create();

        $this->post(route('admin.clients.store'), [
            'name' => 'Ana', 'email' => 'ana@example.com', 'plan_id' => $plan->id, 'service_type' => 'pppoe',
            'username' => 'ana.r', 'service_password' => 'pppoe-secret', 'next_due_date' => '2026-11-15', 'send_welcome' => '1',
        ])->assertSessionHasNoErrors();

        Mail::assertQueued(ClientWelcome::class, function (ClientWelcome $mail) {
            $html = $mail->render();

            return $mail->hasTo('ana@example.com')
                && str_contains($html, 'ana.r')
                && str_contains($html, 'forgot-password?email=ana%40example.com')
                && ! str_contains($html, 'pppoe-secret');
        });
    }

    public function test_no_welcome_email_when_unticked(): void
    {
        $plan = Plan::factory()->create();

        $this->post(route('admin.clients.store'), [
            'name' => 'Ana', 'email' => 'ana@example.com', 'plan_id' => $plan->id, 'service_type' => 'pppoe',
            'username' => 'ana.r', 'service_password' => 'pppoe-secret', 'next_due_date' => '2026-11-15',
        ]);

        Mail::assertNotQueued(ClientWelcome::class);
    }

    public function test_import_sends_welcome_emails_only_when_asked(): void
    {
        $plan = Plan::factory()->create(['name' => 'Fiber 20']);
        $csv = "name,email,plan,username,password,next_due_date\nJuan,juan@example.com,Fiber 20,juan,secret-1,2026-11-15\nAna,ana@example.com,Fiber 20,ana,secret-2,2026-11-15\n";

        $this->post(route('admin.clients.import.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)]);
        Mail::assertNotQueued(ClientWelcome::class);

        $csv2 = "name,email,plan,username,password,next_due_date\nLiza,liza@example.com,Fiber 20,liza,secret-3,2026-11-15\n";
        $this->post(route('admin.clients.import.store'), ['file' => UploadedFile::fake()->createWithContent('b.csv', $csv2), 'send_welcome' => '1']);
        Mail::assertQueued(ClientWelcome::class, 1);
    }

    public function test_welcome_link_prefills_the_forgot_password_email(): void
    {
        auth()->logout();

        $this->get(route('password.request', ['email' => 'ana@example.com']))->assertSee('value="ana@example.com"', false);
        $this->get(route('password.request').'?email[]=x')->assertOk();
    }
}
