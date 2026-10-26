<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRouterAccess;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * One customer after they've been added: change their details, plan, due
 * date, cut-off mode or PPPoE password; cancel or resume the service; turn
 * their portal sign-in off or on. The PPPoE username can't change here — it
 * would mean renaming the secret on the router too.
 */
class ClientAccountController extends Controller
{
    public function edit(Subscription $subscription): View
    {
        $subscription->load(['client.user', 'plan']);

        return view('admin.clients-edit', [
            's' => $subscription,
            'client' => $subscription->client,
            // Hidden plans can't be chosen, except the one they're already on.
            'plans' => Plan::where('is_active', true)->orWhere('id', $subscription->plan_id)->orderBy('price')->get(),
            'openBalance' => $subscription->invoices()->open()->get()->sum(fn ($invoice) => $invoice->balance()),
        ]);
    }

    public function update(Request $request, Subscription $subscription, Billing $billing, RouterGateway $router): RedirectResponse
    {
        $subscription->load('client.user');
        $client = $subscription->client;
        $user = $client->user;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $subscription->plan_id))],
            'next_due_date' => ['required', 'date'],
            'auto_suspend' => ['required', 'boolean'],
            'service_password' => ['nullable', 'string', 'min:6', 'max:64'],
        ]);
        $email = Str::lower(trim($data['email']));
        $notes = [];

        $client->update(['name' => $data['name'], 'email' => $email, 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null]);
        if ($user) {
            $emailChanged = $user->email !== $email;
            $user->forceFill(['name' => $data['name'], 'email' => $email] + ($emailChanged ? ['email_verified_at' => null] : []))->save();
            if ($emailChanged) {
                $notes[] = 'New email saved: they\'ll confirm it with a code at their next sign-in.';
            }
        }

        $dueDate = Carbon::parse($data['next_due_date'])->startOfDay();
        if (! $dueDate->equalTo($subscription->next_due_date) && $dueDate->lt(today())) {
            return back()->withInput()->withErrors(['next_due_date' => 'Pick today or a later date.']);
        }
        if (! $dueDate->equalTo($subscription->next_due_date)) {
            $billing->changeDueDate($subscription, $dueDate);
            $notes[] = "Next due date moved to {$dueDate->format('M j, Y')}.";
        }

        $planChanged = (int) $data['plan_id'] !== $subscription->plan_id;
        $subscription->update([
            'plan_id' => $data['plan_id'],
            'auto_suspend' => $data['auto_suspend'],
            'suspension_hold_until' => $data['auto_suspend'] ? $subscription->suspension_hold_until : null,
        ]);
        if ($planChanged) {
            // Pushes the new speed profile (and reconnects them) if they're connected.
            SyncRouterAccess::dispatch($subscription->fresh());
            $notes[] = 'Plan changed: the new speed applies on the router now; the new price from the next bill.';
        }

        if (filled($data['service_password'] ?? null)) {
            $subscription->update(['password' => $data['service_password']]);
            $notes[] = $this->pushPassword($subscription, $data['service_password'], $router);
        }

        return redirect()->route('admin.clients.edit', $subscription)
            ->with('status', trim("{$client->account_no} saved. ".implode(' ', $notes)));
    }

    public function cancel(Subscription $subscription, Billing $billing): RedirectResponse
    {
        $billing->cancel($subscription);

        return back()->with('status', "{$subscription->username}: service cancelled. They're cut on the router and get no more bills. Unpaid bills stay on their account.");
    }

    public function resume(Request $request, Subscription $subscription, Billing $billing): RedirectResponse
    {
        $data = $request->validate(['next_due_date' => ['required', 'date', 'after_or_equal:today']]);
        $billing->resume($subscription, Carbon::parse($data['next_due_date']));

        return back()->with('status', "{$subscription->username}: service resumed, next due ".Carbon::parse($data['next_due_date'])->format('M j, Y').'. If an old bill is still unpaid, they\'re cut again at midnight unless it\'s paid or set to manual cut-off.');
    }

    /** Portal sign-in on or off. Signing in again is blocked and current sessions end on their next click. */
    public function portalAccess(Request $request, Subscription $subscription): RedirectResponse
    {
        $user = $subscription->client?->user;
        abort_unless($user, 404);

        $enabled = $request->validate(['is_active' => ['required', 'boolean']])['is_active'];
        $user->forceFill(['is_active' => $enabled])->save();

        return back()->with('status', $enabled
            ? "{$subscription->username} can sign in to the portal again."
            : "{$subscription->username} can no longer sign in to the portal.");
    }

    private function pushPassword(Subscription $subscription, string $password, RouterGateway $router): string
    {
        if (! $router->isConfigured()) {
            return 'New PPPoE password saved; the router isn\'t set up yet.';
        }

        try {
            if (! $router->findAccount($subscription->username, $subscription->service_type)) {
                return 'New PPPoE password saved, but the router has no account by that name.';
            }
            $router->setPassword($subscription->username, $password, $subscription->service_type);

            return 'New PPPoE password set on the router: update it on the customer\'s own router too, or it can\'t reconnect.';
        } catch (RouterOsException $e) {
            return 'New PPPoE password saved, but the router couldn\'t be updated ('.$e->getMessage().'). Try again later.';
        }
    }
}
