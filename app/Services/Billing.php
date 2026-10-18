<?php

namespace App\Services;

use App\Jobs\SyncRouterAccess;
use App\Mail\InvoiceIssued;
use App\Mail\PaymentReceived;
use App\Mail\ServiceSuspended;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The billing rules, in one place, for the scheduler, the PayMongo webhook
 * and the admin screens alike.
 *
 * Cycle: monthly on the same day (Jan 31 → Feb 28 → Mar 28 is avoided by
 * addMonthNoOverflow keeping to the original day when it can).
 */
class Billing
{
    /** Issues the invoice for the subscription's next due date, once. */
    public function issueInvoice(Subscription $subscription): Invoice
    {
        $due = $subscription->next_due_date->copy();

        // whereDate: date casts are stored as "Y-m-d 00:00:00" on SQLite, so
        // a plain equality on "Y-m-d" would miss the existing row.
        $existing = $subscription->invoices()->whereDate('due_date', $due->toDateString())->first();
        if ($existing) {
            return $existing;
        }

        return $subscription->invoices()->create([
            'due_date' => $due,
            'period_start' => $due,
            'period_end' => $due->copy()->addMonthNoOverflow()->subDay(),
            'amount' => $subscription->plan->price,
            'status' => Invoice::UNPAID,
        ]);
    }

    /** Emails the client their new bill. */
    public function sendNewInvoice(Invoice $invoice): void
    {
        $this->mail($invoice->subscription, new InvoiceIssued($invoice));
    }

    /**
     * Records a payment against an invoice. Safe to call twice with the same
     * $reference (webhook retries, return page + webhook): the second call
     * returns the first payment and changes nothing.
     *
     * When the invoice becomes fully paid the due date moves forward and a
     * suspended client is reconnected.
     */
    public function recordPayment(
        Invoice $invoice,
        float $amount,
        string $method,
        string $gateway,
        ?string $reference = null,
        ?CarbonInterface $paidAt = null,
        ?User $recordedBy = null,
        ?float $fee = null,
        array $meta = [],
    ): Payment {
        [$payment, $isNew, $restored] = DB::transaction(function () use ($invoice, $amount, $method, $gateway, $reference, $paidAt, $recordedBy, $fee, $meta) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($reference && ($existing = Payment::where('reference', $reference)->first())) {
                return [$existing, false, false];
            }

            $paidAt ??= now();
            $payment = $invoice->payments()->create([
                'amount' => round($amount, 2),
                'method' => $method,
                'gateway' => $gateway,
                'reference' => $reference,
                'gateway_fee' => $fee,
                'recorded_by' => $recordedBy?->id,
                'paid_at' => $paidAt,
                'meta' => $meta ?: null,
            ]);

            $invoice->amount_paid = round((float) $invoice->amount_paid + $amount, 2);
            $fullyPaid = $invoice->amount_paid >= (float) $invoice->amount;
            $invoice->status = $fullyPaid ? Invoice::PAID : Invoice::PARTIAL;
            $invoice->paid_at = $fullyPaid ? $paidAt : null;
            $invoice->save();

            return [$payment, true, $fullyPaid ? $this->advance($invoice) : false];
        });

        if ($isNew) {
            if ($restored) {
                SyncRouterAccess::dispatch($payment->invoice->subscription);
            }
            $this->mail($payment->invoice->subscription, new PaymentReceived($payment, reconnected: $restored));
        }

        return $payment;
    }

    /**
     * Moves the due date past a fully paid invoice and reconnects the
     * subscription if nothing else is overdue. Runs inside recordPayment's
     * transaction. Returns true if the subscription was reactivated.
     *
     * Paid on time (or within grace): the next period starts at the old due
     * date, so paying late doesn't buy free days. Paid while suspended: the
     * client had no service, so the new period starts from today.
     */
    private function advance(Invoice $invoice): bool
    {
        $subscription = Subscription::whereKey($invoice->subscription_id)->lockForUpdate()->first();

        if ($subscription->next_due_date->lte($invoice->due_date)) {
            $start = $invoice->due_date->copy();
            if ($subscription->isSuspended() && now()->startOfDay()->gt($start)) {
                $start = now()->startOfDay();
            }
            $subscription->next_due_date = $start->addMonthNoOverflow();
        }

        $reactivate = $subscription->isSuspended() && ! $this->hasOverdue($subscription);
        if ($reactivate) {
            $subscription->status = Subscription::ACTIVE;
            $subscription->suspended_at = null;
            $subscription->client()->update(['status' => 'active']);
        }

        $subscription->save();

        return $reactivate;
    }

    /** Cuts the subscription off (DB first, then the router via the queue). */
    public function suspend(Subscription $subscription, bool $notify = true): void
    {
        if ($subscription->status !== Subscription::ACTIVE) {
            return;
        }

        $subscription->update(['status' => Subscription::SUSPENDED, 'suspended_at' => now()]);
        $subscription->client()->update(['status' => 'suspended']);
        SyncRouterAccess::dispatch($subscription);

        if ($notify) {
            $this->mail($subscription, new ServiceSuspended($subscription));
        }
    }

    /** Admin override: reconnect without a payment. */
    public function restore(Subscription $subscription): void
    {
        if ($subscription->status !== Subscription::SUSPENDED) {
            return;
        }

        $subscription->update(['status' => Subscription::ACTIVE, 'suspended_at' => null]);
        $subscription->client()->update(['status' => 'active']);
        SyncRouterAccess::dispatch($subscription);
    }

    /** Has an open invoice past its due date plus the grace period. */
    public function hasOverdue(Subscription $subscription): bool
    {
        return $subscription->invoices()->open()
            ->whereDate('due_date', '<=', $this->overdueCutoff())
            ->exists();
    }

    /**
     * Invoices due on or before this date are overdue (= today − grace − 1).
     * The due date itself is always payable: with no grace days, a bill due
     * on the 15th is cut at midnight going into the 16th.
     */
    public function overdueCutoff(): string
    {
        return now()->subDays(config('isp.grace_days') + 1)->toDateString();
    }

    /** The day service pauses if this invoice is still unpaid (for emails and screens). */
    public function pausesOn(Invoice $invoice): CarbonInterface
    {
        return $invoice->due_date->copy()->addDays(config('isp.grace_days') + 1);
    }

    private function mail(Subscription $subscription, $mailable): void
    {
        $client = $subscription->client;
        $to = $client?->routeNotificationForMail();
        if (! $to) {
            return;
        }

        try {
            Mail::to($to, $client->name)->queue($mailable);
        } catch (\Throwable $e) {
            // A mail problem must never undo a payment or a suspension.
            Log::error('Billing email failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }
}
