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
        // Skips customers set to manual cut-off, and accounts an admin kept on
        // until a date that hasn't passed yet.
        Subscription::where('status', Subscription::ACTIVE)
            ->where('auto_suspend', true)
            ->where(fn ($q) => $q->whereNull('suspension_hold_until')->orWhereDate('suspension_hold_until', '<', today()->toDateString()))
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
