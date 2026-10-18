<?php

namespace App\Console\Commands;

use App\Mail\InvoiceReminder;
use App\Models\Invoice;
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

        $this->info("Queued {$count} reminder(s).");

        return self::SUCCESS;
    }
}
