<?php

namespace App\Console\Commands;

use App\Jobs\SyncRouterAccess;
use App\Models\Subscription;
use App\Services\Billing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('isp:access {username} {action : suspend or restore} {--no-mail : Do not email the client}')]
#[Description('Manually suspend or restore one subscription and apply it to the router right away')]
class SetAccess extends Command
{
    public function handle(Billing $billing): int
    {
        $subscription = Subscription::where('username', $this->argument('username'))->first();
        if (! $subscription) {
            $this->error('No subscription with that username.');

            return self::FAILURE;
        }

        match ($this->argument('action')) {
            'suspend' => $billing->suspend($subscription, notify: ! $this->option('no-mail')),
            'restore' => $billing->restore($subscription),
            default => null,
        };

        // Apply now so you can watch the router change. The queued copy that
        // suspend()/restore() pushed is harmless: same target state.
        $subscription->refresh();
        SyncRouterAccess::dispatchSync($subscription);
        $log = $subscription->routerLogs()->latest('id')->first();

        $this->line('Status: '.$subscription->status);
        $log?->success ? $this->info('Router: '.$log->message) : $this->error('Router: '.($log?->message ?? 'no result'));

        return $log?->success ? self::SUCCESS : self::FAILURE;
    }
}
