<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterAccess;
use App\Mail\InvoiceIssued;
use App\Mail\InvoiceReminder;
use App\Mail\PaymentReceived;
use App\Mail\ServiceSuspended;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

class BillingAutomationTest extends TestCase
{
    use RefreshDatabase;

    private FakeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new FakeRouter;
        $this->app->instance(RouterGateway::class, $this->router);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    public function test_invoices_are_issued_once_within_the_lead_time(): void
    {
        $soon = Subscription::factory()->create(['next_due_date' => '2026-10-20']);
        Subscription::factory()->create(['next_due_date' => '2026-11-30']);

        $this->artisan('billing:issue-invoices')->assertSuccessful();
        $this->artisan('billing:issue-invoices')->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::first();
        $this->assertSame($soon->id, $invoice->subscription_id);
        $this->assertSame('2026-10-20', $invoice->due_date->toDateString());
        $this->assertSame('2026-11-19', $invoice->period_end->toDateString());
        $this->assertMatchesRegularExpression('/^INV-2026-\d{6}$/', $invoice->number);
    }

    public function test_reminder_goes_out_once_three_days_before(): void
    {
        Mail::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-18']);
        app(Billing::class)->issueInvoice($sub);

        $this->artisan('billing:send-reminders');
        $this->artisan('billing:send-reminders');

        Mail::assertQueued(InvoiceReminder::class, 1);
    }

    public function test_new_bill_is_emailed_once_when_issued(): void
    {
        Mail::fake();
        Subscription::factory()->create(['next_due_date' => '2026-10-20']);

        $this->artisan('billing:issue-invoices');
        $this->artisan('billing:issue-invoices');

        Mail::assertQueued(InvoiceIssued::class, 1);
    }

    public function test_bill_emails_say_when_service_would_pause(): void
    {
        config(['isp.grace_days' => 2]);
        $invoice = app(Billing::class)->issueInvoice(Subscription::factory()->create(['next_due_date' => '2026-10-20']));

        (new InvoiceIssued($invoice))->assertSeeInHtml('service pauses on October 23');
        (new InvoiceReminder($invoice))->assertSeeInHtml('service pauses on October 23');
    }

    public function test_bill_due_today_can_still_be_paid_today(): void
    {
        Queue::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-15']);
        app(Billing::class)->issueInvoice($sub);

        $this->artisan('billing:suspend-overdue');
        $this->assertSame('active', $sub->fresh()->status);

        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 0));
        $this->artisan('billing:suspend-overdue');
        $this->assertSame('suspended', $sub->fresh()->status);
    }

    public function test_overdue_subscription_is_suspended_and_cut_on_the_router(): void
    {
        Mail::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-14']);
        app(Billing::class)->issueInvoice($sub);
        $this->router->addAccount($sub->username);

        $this->artisan('billing:suspend-overdue')->assertSuccessful();

        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame('suspended', $sub->client->fresh()->status);
        $this->assertSame('true', $this->router->accounts[$sub->username]['disabled']);
        $this->assertArrayNotHasKey($sub->username, $this->router->active, 'live session dropped');
        $this->assertDatabaseHas('router_logs', ['subscription_id' => $sub->id, 'action' => 'disable', 'success' => true]);
        Mail::assertQueued(ServiceSuspended::class);
    }

    public function test_grace_days_delay_suspension(): void
    {
        config(['isp.grace_days' => 2]);
        Queue::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-14']);
        app(Billing::class)->issueInvoice($sub);

        // Due Oct 14 + 2 grace days: still on through Oct 16, cut on Oct 17.
        $this->artisan('billing:suspend-overdue');
        $this->travel(1)->day();
        $this->artisan('billing:suspend-overdue');
        $this->assertSame('active', $sub->fresh()->status);

        $this->travel(1)->day();
        $this->artisan('billing:suspend-overdue');
        $this->assertSame('suspended', $sub->fresh()->status);
    }

    public function test_paid_invoice_is_not_suspended(): void
    {
        Queue::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-15']);
        $invoice = app(Billing::class)->issueInvoice($sub);
        app(Billing::class)->recordPayment($invoice, 999, 'cash', 'manual');

        $this->artisan('billing:suspend-overdue');

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame('2026-11-15', $sub->fresh()->next_due_date->toDateString());
    }

    public function test_paying_while_suspended_reconnects_and_restarts_the_cycle_today(): void
    {
        Mail::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-01']);
        $invoice = app(Billing::class)->issueInvoice($sub);
        $this->router->addAccount($sub->username);
        $this->artisan('billing:suspend-overdue');

        app(Billing::class)->recordPayment($invoice, 999, 'gcash', 'paymongo', 'pay_123');

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('2026-11-15', $sub->next_due_date->toDateString());
        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);
        Mail::assertQueued(PaymentReceived::class, fn (PaymentReceived $mail) => $mail->reconnected
            && str_contains($mail->render(), 'Your internet is back on'));
    }

    public function test_receipt_for_an_on_time_payment_does_not_mention_reconnecting(): void
    {
        Mail::fake();
        $invoice = app(Billing::class)->issueInvoice(Subscription::factory()->create(['next_due_date' => '2026-10-20']));

        app(Billing::class)->recordPayment($invoice, 999, 'cash', 'manual');

        Mail::assertQueued(PaymentReceived::class, fn (PaymentReceived $mail) => ! $mail->reconnected
            && ! str_contains($mail->render(), 'back on'));
    }

    public function test_partial_payment_keeps_the_invoice_open(): void
    {
        Queue::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-15']);
        $invoice = app(Billing::class)->issueInvoice($sub);

        app(Billing::class)->recordPayment($invoice, 500, 'cash', 'manual');

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertSame(499.0, $invoice->balance());
        $this->assertSame('2026-10-15', $sub->fresh()->next_due_date->toDateString());
    }

    public function test_profile_mode_moves_to_the_suspended_profile(): void
    {
        config(['isp.suspend_mode' => 'profile']);
        Mail::fake();
        $sub = Subscription::factory()->create(['next_due_date' => '2026-10-15']);
        $this->router->addAccount($sub->username, '20M');

        app(Billing::class)->suspend($sub);
        $this->assertSame('suspended', $this->router->accounts[$sub->username]['profile']);
        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);

        app(Billing::class)->restore($sub->fresh());
        $this->assertSame('20M', $this->router->accounts[$sub->username]['profile']);
    }

    public function test_missing_router_account_is_logged_not_retried(): void
    {
        Mail::fake();
        $sub = Subscription::factory()->create();

        app(Billing::class)->suspend($sub);

        $this->assertDatabaseHas('router_logs', ['subscription_id' => $sub->id, 'success' => false]);
    }

    public function test_router_down_throws_so_the_queue_retries(): void
    {
        $sub = Subscription::factory()->create(['status' => 'suspended']);
        $this->router->down = true;

        $this->expectException(RouterOsException::class);
        (new SyncRouterAccess($sub))->handle($this->router);
    }

    public function test_sync_job_reads_the_current_status_not_the_queued_one(): void
    {
        $sub = Subscription::factory()->create(['status' => 'suspended']);
        $this->router->addAccount($sub->username, disabled: false);
        $job = new SyncRouterAccess($sub);

        // Paid before the worker got to it.
        Subscription::whereKey($sub->id)->update(['status' => 'active']);
        $job->handle($this->router);

        $this->assertSame('false', $this->router->accounts[$sub->username]['disabled']);
    }
}
