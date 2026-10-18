<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing;
use App\Support\Automation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:issue-invoices')]
#[Description('Issue invoices for subscriptions due within the lead time (isp.invoice_lead_days)')]
class IssueInvoices extends Command
{
    public function handle(Billing $billing): int
    {
        $until = now()->addDays(config('isp.invoice_lead_days'))->toDateString();
        $count = 0;

        Subscription::with('plan')
            ->where('status', '!=', Subscription::CANCELLED)
            ->whereDate('next_due_date', '<=', $until)
            ->chunkById(200, function ($subscriptions) use ($billing, &$count) {
                foreach ($subscriptions as $subscription) {
                    $invoice = $billing->issueInvoice($subscription);
                    if ($invoice->wasRecentlyCreated) {
                        $billing->sendNewInvoice($invoice);
                        $count++;
                    }
                }
            });

        $summary = "Issued {$count} invoice(s).";
        Automation::record('billing:issue-invoices', $summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
