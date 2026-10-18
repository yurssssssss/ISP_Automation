<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing;
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
                    $count += (int) $billing->issueInvoice($subscription)->wasRecentlyCreated;
                }
            });

        $this->info("Issued {$count} invoice(s).");

        return self::SUCCESS;
    }
}
