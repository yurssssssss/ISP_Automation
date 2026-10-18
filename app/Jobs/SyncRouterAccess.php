<?php

namespace App\Jobs;

use App\Models\RouterLog;
use App\Models\Subscription;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes the router match the subscription's CURRENT status in the database:
 * active → account enabled on its plan's profile; suspended/cancelled → cut.
 *
 * Reading the status when the job runs (not when it was queued) means a
 * suspend and a restore queued close together can't land in the wrong order,
 * and the same job doubles as the nightly reconcile. Retried with back-off
 * while the router is unreachable.
 */
class SyncRouterAccess implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public Subscription $subscription)
    {
        // Wait for the suspend/payment transaction to commit, so the job
        // never reads the status from before the change.
        $this->afterCommit();
    }

    /** One WAITING sync per subscription is enough — it reads the latest status when it starts. */
    public function uniqueId(): string
    {
        return (string) $this->subscription->id;
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900, 1800];
    }

    public function handle(RouterGateway $router): void
    {
        $subscription = $this->subscription->fresh(['plan']);
        $enable = $subscription->status === Subscription::ACTIVE;
        $service = $subscription->service_type;
        $username = $subscription->username;

        $account = $router->findAccount($username, $service);
        if (! $account) {
            // Retrying can't fix a missing account — record it and stop.
            $this->log($subscription, $enable, false, "No {$service} account named \"{$username}\" on the router.");
            $this->delete();

            return;
        }

        if (config('isp.suspend_mode') === 'profile' && $service === 'pppoe') {
            $profile = $enable ? $subscription->plan->mikrotik_profile : config('isp.suspended_profile');
            $changed = ($account['profile'] ?? null) !== $profile || ($account['disabled'] ?? 'false') === 'true';
            $router->setDisabled($username, false, $service);
            $router->setProfile($username, $profile, $service);
            // A new profile only applies on the next connect.
            $kicked = $changed ? $router->kick($username, $service) : 0;
            $message = "Profile set to \"{$profile}\"".($kicked ? ", {$kicked} session(s) reconnected" : '');
        } else {
            $router->setDisabled($username, ! $enable, $service);
            // Disabling the account doesn't end a session already connected.
            $kicked = $enable ? 0 : $router->kick($username, $service);
            $message = ($enable ? 'Account enabled' : 'Account disabled').($kicked ? ", {$kicked} session(s) dropped" : '');
        }

        $this->log($subscription, $enable, true, $message);
    }

    public function failed(?Throwable $e): void
    {
        $subscription = $this->subscription->fresh();
        if ($subscription) {
            $this->log($subscription, $subscription->status === Subscription::ACTIVE, false,
                'Gave up after '.$this->tries.' tries: '.($e instanceof RouterOsException ? $e->getMessage() : ($e?->getMessage() ?? 'unknown error')));
        }
    }

    private function log(Subscription $subscription, bool $enable, bool $success, string $message): void
    {
        RouterLog::create([
            'subscription_id' => $subscription->id,
            'username' => $subscription->username,
            'action' => $enable ? 'enable' : 'disable',
            'success' => $success,
            'message' => $message,
        ]);
    }
}
