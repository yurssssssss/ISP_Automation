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

class DashboardController extends Controller
{
    public function index()
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

        return back()->with('status', "{$subscription->username} suspended. The router will be updated by the queue worker.");
    }

    public function restore(Subscription $subscription, Billing $billing)
    {
        $billing->restore($subscription);

        return back()->with('status', "{$subscription->username} restored. The router will be updated by the queue worker.");
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
