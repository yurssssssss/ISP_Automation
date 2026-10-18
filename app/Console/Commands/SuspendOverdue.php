<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing;
use App\Support\Automation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:suspend-overdue')]
#[Description('Suspend active subscriptions with an unpaid invoice past its due date + grace days')]
class SuspendOverdue extends Command
{
    public function handle(Billing $billing): int
    {
        $count = 0;

        // "On or before the cutoff", not "equal to today", so a missed
        // midnight run is caught up on the next one.
        Subscription::where('status', Subscription::ACTIVE)
            ->whereHas('invoices', fn ($q) => $q->open()->whereDate('due_date', '<=', $billing->overdueCutoff()))
            ->with('client')
            ->chunkById(200, function ($subscriptions) use ($billing, &$count) {
                foreach ($subscriptions as $subscription) {
                    $billing->suspend($subscription);
                    $count++;
                }
            });

        $summary = "Suspended {$count} subscription(s).";
        Automation::record('billing:suspend-overdue', $summary);
        $this->info($summary);

        return self::SUCCESS;
    }
}
