<?php

namespace App\Console\Commands;

use App\Jobs\SyncRouterAccess;
use App\Models\Subscription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mikrotik:sync {username? : Only this subscription} {--now : Run immediately instead of queueing}')]
#[Description('Make the router match the database (enable active accounts, cut suspended ones)')]
class SyncRouter extends Command
{
    public function handle(): int
    {
        $query = Subscription::query()->when($this->argument('username'), fn ($q, $u) => $q->where('username', $u));

        if (! $query->exists()) {
            $this->warn('No matching subscriptions.');

            return self::FAILURE;
        }

        foreach ($query->cursor() as $subscription) {
            if ($this->option('now')) {
                SyncRouterAccess::dispatchSync($subscription);
                $log = $subscription->routerLogs()->latest('id')->first();
                $this->line(sprintf('%-20s %-10s %-5s %s', $subscription->username, $subscription->status, $log?->success ? 'OK' : 'FAIL', $log?->message));
            } else {
                SyncRouterAccess::dispatch($subscription);
            }
        }

        if (! $this->option('now')) {
            $this->info('Queued. Run `php artisan queue:work` to process.');
        }

        return self::SUCCESS;
    }
}
