<?php

namespace App\Console\Commands;

use App\Mail\InvoiceReminder;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\Automation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('billing:send-reminders')]
#[Description('Email clients whose unpaid invoice is due within isp.reminder_days (once per invoice)')]
class SendReminders extends Command
{
    public function handle(): int
    {
        $count = 0;

        // A window, not an exact day, so a missed run still sends tomorrow.
        Invoice::open()
            ->whereNull('reminded_at')
            // A cancelled customer's old balance isn't chased by email.
            ->whereHas('subscription', fn ($q) => $q->where('status', '!=', Subscription::CANCELLED))
            ->whereDate('due_date', '>=', today()->toDateString())
            ->whereDate('due_date', '<=', today()->addDays(config('isp.reminder_days'))->toDateString())
            ->with('subscription.client.user', 'subscription.plan')
            ->chunkById(200, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    $client = $invoice->subscription->client;
                    if ($to = $client->routeNotificationForMail()) {
                        Mail::to($to, $client->name)->queue(new InvoiceReminder($invoice));
                        $count++;
                    }
                    $invoice->update(['reminded_at' => now()]);
                }
            });

        $summary = "Queued {$count} reminder(s).";
        Automation::record('billing:send-reminders', $summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
