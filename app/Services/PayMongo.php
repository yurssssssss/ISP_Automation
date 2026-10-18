<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the PayMongo REST API (https://docs.paymongo.com):
 * Checkout Sessions for paying an invoice, and webhook signature checks.
 */
class PayMongo
{
    private const API = 'https://api.paymongo.com/v1';

    /** PayMongo payment source type → our payments.method value. */
    private const METHOD_MAP = [
        'gcash' => 'gcash',
        'paymaya' => 'maya',
        'card' => 'card',
        'qrph' => 'qrph',
        'grab_pay' => 'grab_pay',
        'dob' => 'online_banking',
        'dob_ubp' => 'online_banking',
    ];

    public function __construct(private Billing $billing) {}

    public static function enabled(): bool
    {
        return filled(config('services.paymongo.secret_key'));
    }

    /** True for live keys (sk_live_...), false for test keys. */
    public static function liveMode(): bool
    {
        return str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_');
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.paymongo.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->baseUrl(self::API);
    }

    /** Starts a checkout for the invoice's remaining balance; returns the checkout_url. */
    public function checkoutForInvoice(Invoice $invoice, string $successUrl, string $cancelUrl): string
    {
        $subscription = $invoice->subscription()->with(['plan', 'client'])->first();
        $client = $subscription->client;
        $period = $invoice->period_start->format('M j').' – '.$invoice->period_end->format('M j, Y');

        $session = $this->client()->post('/checkout_sessions', ['data' => ['attributes' => array_filter([
            'line_items' => [[
                'name' => "Internet {$subscription->plan->name} ({$period})",
                'amount' => (int) round($invoice->balance() * 100), // centavos
                'currency' => 'PHP',
                'quantity' => 1,
            ]],
            'payment_method_types' => config('services.paymongo.methods'),
            'reference_number' => $invoice->number,
            'description' => "{$invoice->number} · Account {$client->account_no}",
            'billing' => array_filter([
                'name' => $client->name,
                'email' => $client->routeNotificationForMail(),
                'phone' => $client->phone,
            ]) ?: null,
            'send_email_receipt' => false,
            'show_line_items' => true,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['invoice_id' => (string) $invoice->id],
        ])]])->throw()->json('data');

        $invoice->update(['checkout_session_id' => $session['id']]);

        return $session['attributes']['checkout_url'];
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->client()->get('/checkout_sessions/'.$id)->throw()->json('data');
    }

    /**
     * Records the paid payment inside a checkout_session resource (from the
     * webhook or from asking PayMongo on the return page). Idempotent: the
     * PayMongo payment id is the payment's unique reference.
     */
    public function recordPaidSession(Invoice $invoice, array $session): ?Payment
    {
        $paid = collect(data_get($session, 'attributes.payments', []))
            ->first(fn ($payment) => data_get($payment, 'attributes.status') === 'paid');

        if (! $paid) {
            return null;
        }

        $attrs = $paid['attributes'];
        $source = data_get($attrs, 'source.type');

        $expected = (int) round($invoice->balance() * 100);
        if ($invoice->isOpen() && (int) $attrs['amount'] !== $expected) {
            Log::warning("PayMongo charged {$attrs['amount']} centavos for {$invoice->number}, balance was {$expected}");
        }

        return $this->billing->recordPayment(
            $invoice,
            round(((int) $attrs['amount']) / 100, 2),
            self::METHOD_MAP[$source] ?? ($source ?: 'online'),
            'paymongo',
            $paid['id'],
            // Carbon 3 reads unix timestamps as UTC unless told otherwise.
            isset($attrs['paid_at']) ? Carbon::createFromTimestamp($attrs['paid_at'], config('app.timezone')) : now(),
            fee: isset($attrs['fee']) ? round(((int) $attrs['fee']) / 100, 2) : null,
            meta: ['checkout_session_id' => $session['id'] ?? null],
        );
    }

    /**
     * Paymongo-Signature: t=<timestamp>,te=<test sig>,li=<live sig>, where
     * each signature is HMAC-SHA256("<timestamp>.<raw body>", webhook secret).
     * Test-mode events are signed in te, live-mode events in li.
     */
    public static function verifySignature(?string $header, string $rawBody, bool $livemode, int $toleranceSeconds = 300): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $livemode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // Replay protection: reject events signed long ago.
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret), $signature);
    }
}
