<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\PayMongo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** The client's own page: plan, status, bills, and the Pay button. */
class PortalController extends Controller
{
    public function dashboard(Request $request)
    {
        $client = $request->user()->client;
        abort_unless($client, 404);

        return view('portal.dashboard', [
            'client' => $client,
            'subscriptions' => $client->subscriptions()->with('plan')->get(),
            'invoices' => $client->invoices()->with('payments')->latest('due_date')->limit(24)->get(),
            'payOnline' => PayMongo::enabled(),
        ]);
    }

    public function pay(Request $request, Invoice $invoice, PayMongo $paymongo)
    {
        $this->authorizeInvoice($request, $invoice);

        if (! $invoice->isOpen()) {
            return redirect()->route('portal.dashboard')->with('status', "{$invoice->number} is already paid.");
        }
        if (! PayMongo::enabled()) {
            return back()->with('error', 'Online payment is not available right now.');
        }

        try {
            $url = $paymongo->checkoutForInvoice($invoice,
                route('portal.invoices.return', $invoice),
                route('portal.dashboard'));
        } catch (\Throwable $e) {
            Log::error('PayMongo checkout failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'We could not start the payment. Please try again in a few minutes.');
        }

        return redirect()->away($url);
    }

    /**
     * PayMongo's success_url. The webhook normally records the payment; this
     * asks PayMongo directly too, so it shows as paid even if the webhook is
     * late (or not set up yet on a dev machine).
     */
    public function returned(Request $request, Invoice $invoice, PayMongo $paymongo)
    {
        $this->authorizeInvoice($request, $invoice);

        if ($invoice->isOpen() && $invoice->checkout_session_id) {
            try {
                $paymongo->recordPaidSession($invoice, $paymongo->retrieveCheckoutSession($invoice->checkout_session_id));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('portal.dashboard')->with('status', $invoice->fresh()->isOpen()
            ? 'Thanks! Your payment is being confirmed — this page will show it as paid within a minute or two.'
            : 'Payment received. Thank you!');
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        // 404 rather than 403, so invoice ids can't be probed.
        abort_unless($invoice->subscription->client->user_id === $request->user()->id, 404);
    }
}
