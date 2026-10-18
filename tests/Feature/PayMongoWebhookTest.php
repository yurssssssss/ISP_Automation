<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterAccess;
use App\Mail\PaymentReceived;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayMongoWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paymongo.secret_key' => 'sk_test_abc',
            'services.paymongo.webhook_secret' => 'whsk_test',
        ]);
        Queue::fake();
        Mail::fake();
    }

    private function event(Invoice $invoice, string $eventId = 'evt_1', int $centavos = 99900): array
    {
        return ['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => [
            'type' => 'checkout_session.payment.paid',
            'livemode' => false,
            'data' => ['id' => 'cs_123', 'type' => 'checkout_session', 'attributes' => [
                'metadata' => ['invoice_id' => (string) $invoice->id],
                'payments' => [['id' => 'pay_abc', 'attributes' => [
                    'status' => 'paid', 'amount' => $centavos, 'fee' => 2000,
                    'paid_at' => now()->timestamp, 'source' => ['type' => 'gcash'],
                ]]],
            ]],
        ]]];
    }

    private function send(array $event, ?string $signature = null)
    {
        $raw = json_encode($event);
        $t = time();
        $signature ??= "t={$t},te=".hash_hmac('sha256', "{$t}.{$raw}", 'whsk_test').',li=';

        return $this->call('POST', '/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $raw);
    }

    private function overdueInvoice(): Invoice
    {
        $sub = Subscription::factory()->create(['next_due_date' => today()->subDays(5), 'status' => 'suspended', 'suspended_at' => now()]);

        return app(Billing::class)->issueInvoice($sub);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $invoice = $this->overdueInvoice();

        $this->send($this->event($invoice), 't='.time().',te=forged,li=')->assertStatus(400);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_old_signature_is_rejected(): void
    {
        $invoice = $this->overdueInvoice();
        $raw = json_encode($this->event($invoice));
        $t = time() - 3600;

        $this->send($this->event($invoice), "t={$t},te=".hash_hmac('sha256', "{$t}.{$raw}", 'whsk_test'))->assertStatus(400);
    }

    public function test_paid_event_marks_paid_and_reconnects(): void
    {
        $invoice = $this->overdueInvoice();

        $this->send($this->event($invoice))->assertOk()->assertJson(['received' => true]);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('active', $invoice->subscription->status);
        $this->assertDatabaseHas('payments', ['reference' => 'pay_abc', 'method' => 'gcash', 'amount' => 999, 'gateway_fee' => 20]);
        Queue::assertPushed(SyncRouterAccess::class);
        Mail::assertQueued(PaymentReceived::class, 1);
    }

    public function test_repeated_event_is_handled_once(): void
    {
        $invoice = $this->overdueInvoice();

        $this->send($this->event($invoice))->assertOk();
        $this->send($this->event($invoice))->assertOk()->assertJson(['duplicate' => true]);
        // A different event id carrying the same PayMongo payment.
        $this->send($this->event($invoice, 'evt_2'))->assertOk();

        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(999.0, (float) $invoice->fresh()->amount_paid);
        Mail::assertQueued(PaymentReceived::class, 1);
    }

    public function test_live_event_is_ignored_with_test_keys(): void
    {
        $invoice = $this->overdueInvoice();
        $event = $this->event($invoice);
        $event['data']['attributes']['livemode'] = true;
        $raw = json_encode($event);
        $t = time();

        $this->send($event, "t={$t},te=,li=".hash_hmac('sha256', "{$t}.{$raw}", 'whsk_test'))
            ->assertOk()->assertJson(['ignored' => 'mode mismatch']);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_client_cannot_pay_someone_elses_invoice(): void
    {
        $invoice = $this->overdueInvoice();
        $other = Client::factory()->withLogin()->create();

        $this->actingAs($other->user)->post(route('portal.invoices.pay', $invoice))->assertNotFound();
    }
}
