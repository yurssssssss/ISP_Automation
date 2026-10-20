<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RouterLog;
use App\Models\Subscription;
use App\Services\Billing;
use App\Support\Automation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Billing $billing)
    {
        return view('admin.dashboard', [
            'stats' => [
                'clients' => Client::count(),
                'active' => Subscription::where('status', Subscription::ACTIVE)->count(),
                'suspended' => Subscription::where('status', Subscription::SUSPENDED)->count(),
                'receivable' => (float) Invoice::open()->selectRaw('COALESCE(SUM(amount - amount_paid), 0) as total')->value('total'),
                'overdue' => Invoice::open()->whereDate('due_date', '<', today())->count(),
                'collected' => (float) Payment::where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            ],
            'subscriptions' => Subscription::with(['client', 'plan'])
                // Restoring one of these needs a "keep connected until" date.
                ->withExists(['invoices as has_overdue' => fn ($q) => $q->open()->whereDate('due_date', '<=', $billing->overdueCutoff())])
                ->where('status', '!=', Subscription::CANCELLED)
                ->orderByRaw("CASE WHEN status = 'suspended' THEN 0 ELSE 1 END")
                ->orderBy('next_due_date')
                ->limit(100)
                ->get(),
            'openInvoices' => Invoice::open()->with('subscription.client')->orderBy('due_date')->limit(50)->get(),
            'routerLogs' => RouterLog::latest('id')->limit(15)->get(),
            'automation' => Automation::status(),
        ]);
    }

    public function suspend(Subscription $subscription, Billing $billing)
    {
        $billing->suspend($subscription);

        return back()->with('status', "{$subscription->username} suspended. The router will be updated by the queue worker. They're reconnected automatically once their bills are paid, or when you click Restore.");
    }

    /**
     * Reconnect by hand. If a bill is still unpaid (and the customer is on
     * automatic cut-off), the admin says how long to keep them on — otherwise
     * the next midnight run would cut them again straight away.
     */
    public function restore(Request $request, Subscription $subscription, Billing $billing)
    {
        $stillOwes = $subscription->auto_suspend && $billing->hasOverdue($subscription);

        $data = $request->validate([
            'keep_on_until' => [$stillOwes ? 'required' : 'nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(60)->toDateString()],
        ], [
            'keep_on_until.required' => "{$subscription->username} still has an unpaid bill: choose how long to keep them connected.",
            'keep_on_until.after_or_equal' => 'Pick today or a later date.',
            'keep_on_until.before_or_equal' => 'Pick a date within the next 60 days.',
        ]);

        $keepOnUntil = $stillOwes ? Carbon::parse($data['keep_on_until']) : null;
        $billing->restore($subscription, $keepOnUntil);

        return back()->with('status', $keepOnUntil
            ? "{$subscription->username} restored and kept connected through {$keepOnUntil->format('M j, Y')}. If the bill is still unpaid after that, they're cut again automatically."
            : "{$subscription->username} restored. The router will be updated by the queue worker.");
    }

    /** Automatic: cut at midnight when unpaid after the due date. Manual: only when an admin clicks Suspend. */
    public function cutoffMode(Request $request, Subscription $subscription)
    {
        $automatic = $request->validate(['auto_suspend' => ['required', 'boolean']])['auto_suspend'];
        $subscription->update(['auto_suspend' => $automatic, 'suspension_hold_until' => null]);

        return back()->with('status', $automatic
            ? "{$subscription->username}: automatic cut-off is on. They're cut at midnight if a bill is still unpaid after its due date."
            : "{$subscription->username}: manual cut-off. The system never cuts them automatically; use Suspend when needed.");
    }

    /** Cash collected at the office or by a field agent. */
    public function recordCash(Request $request, Invoice $invoice, Billing $billing)
    {
        abort_unless($invoice->isOpen(), 422, 'This invoice is already settled.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:'.$invoice->balance()],
            'reference' => ['nullable', 'string', 'max:100', 'unique:payments,reference'],
        ]);

        $billing->recordPayment($invoice, (float) $data['amount'], 'cash', 'manual', $data['reference'] ?? null, now(), $request->user());

        return back()->with('status', 'Payment of ₱'.number_format((float) $data['amount'], 2)." recorded for {$invoice->number}.");
    }
}
