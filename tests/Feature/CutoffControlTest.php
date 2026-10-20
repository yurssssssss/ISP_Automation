<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

/** Automatic vs manual cut-off, and restoring a customer who still owes. */
class CutoffControlTest extends TestCase
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
    }

    /** A subscription whose bill was due Oct 15 and is still unpaid. */
    private function overdue(array $attributes = []): Subscription
    {
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-15'] + $attributes);
        app(Billing::class)->issueInvoice($sub);
        $this->router->addAccount($sub->username);

        return $sub;
    }

    private function midnight(string $date): void
    {
        $this->travelTo(now()->parse($date)->setTime(0, 0));
        $this->artisan('billing:suspend-overdue');
    }

    public function test_manual_cut_off_customers_are_never_cut_automatically(): void
    {
        $manual = $this->overdue(['auto_suspend' => false]);
        $automatic = $this->overdue();

        $this->artisan('billing:suspend-overdue');

        $this->assertSame('active', $manual->fresh()->status);
        $this->assertSame('false', $this->router->accounts[$manual->username]['disabled']);
        $this->assertSame('suspended', $automatic->fresh()->status);
    }

    public function test_manual_cut_off_customers_can_still_be_suspended_by_hand(): void
    {
        $manual = $this->overdue(['auto_suspend' => false]);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.subscriptions.suspend', $manual));

        $this->assertSame('suspended', $manual->fresh()->status);
        $this->assertSame('true', $this->router->accounts[$manual->username]['disabled']);
    }

    public function test_restoring_someone_who_still_owes_keeps_them_on_until_the_chosen_date(): void
    {
        $sub = $this->overdue();
        $this->artisan('billing:suspend-overdue');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.subscriptions.restore', $sub), ['keep_on_until' => '2026-10-23'])
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'kept connected through Oct 23, 2026'));

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);

        // Still unpaid, but kept on through the 23rd…
        $this->midnight('2026-10-21');
        $this->midnight('2026-10-23');
        $this->assertSame('active', $sub->fresh()->status);

        // …and cut again automatically once that date has passed.
        $this->midnight('2026-10-24');
        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame('true', $this->router->accounts[$sub->username]['disabled']);
    }

    public function test_restoring_someone_who_still_owes_requires_a_date(): void
    {
        $sub = $this->overdue();
        $this->artisan('billing:suspend-overdue');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.subscriptions.restore', $sub))
            ->assertSessionHasErrors(['keep_on_until' => "{$sub->username} still has an unpaid bill: choose how long to keep them connected."]);

        $this->assertSame('suspended', $sub->fresh()->status);
    }

    public function test_keep_on_date_must_be_within_sixty_days(): void
    {
        $sub = $this->overdue();
        $this->artisan('billing:suspend-overdue');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.subscriptions.restore', $sub), ['keep_on_until' => '2027-01-31'])
            ->assertSessionHasErrors(['keep_on_until' => 'Pick a date within the next 60 days.']);
    }

    public function test_restoring_someone_who_owes_nothing_needs_no_date(): void
    {
        $sub = Subscription::factory()->create(['next_due_date' => '2026-11-15']);
        app(Billing::class)->suspend($sub, notify: false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.subscriptions.restore', $sub))
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertNull($sub->fresh()->suspension_hold_until);
    }

    public function test_paying_clears_the_keep_on_date(): void
    {
        $sub = $this->overdue();
        $this->artisan('billing:suspend-overdue');
        app(Billing::class)->restore($sub->fresh(), now()->parse('2026-10-23'));

        app(Billing::class)->recordPayment($sub->invoices()->first(), 999, 'cash', 'manual');

        $this->assertNull($sub->fresh()->suspension_hold_until);
    }

    public function test_admin_switches_a_customer_between_automatic_and_manual(): void
    {
        $sub = Subscription::factory()->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('admin.subscriptions.cutoff', $sub), ['auto_suspend' => 0])
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'manual cut-off'));
        $this->assertFalse($sub->fresh()->auto_suspend);
        $this->get(route('admin.dashboard'))->assertSee('Manual')->assertSee('Set automatic');

        $this->post(route('admin.subscriptions.cutoff', $sub), ['auto_suspend' => 1]);
        $this->assertTrue($sub->fresh()->auto_suspend);
    }

    public function test_customers_cannot_change_cut_off_settings(): void
    {
        $sub = Subscription::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.subscriptions.cutoff', $sub), ['auto_suspend' => 0])
            ->assertForbidden();

        $this->assertTrue($sub->fresh()->auto_suspend);
    }

    public function test_new_client_can_start_on_manual_cut_off(): void
    {
        $plan = Plan::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.clients.store'), [
            'name' => 'Juan', 'email' => 'juan@example.com', 'plan_id' => $plan->id, 'service_type' => 'pppoe',
            'username' => 'juan01', 'service_password' => 'secret12', 'next_due_date' => '2026-11-15', 'auto_suspend' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Subscription::where('username', 'juan01')->value('auto_suspend'));
    }
}
