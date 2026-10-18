<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\PayMongo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/paymongo — PayMongo's server-to-server notification.
 * Only a correctly signed request is trusted; each event id is handled once.
 */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, PayMongo $paymongo)
    {
        $raw = $request->getContent();
        $event = json_decode($raw, true) ?: [];
        $livemode = (bool) data_get($event, 'data.attributes.livemode', false);

        if (! PayMongo::verifySignature($request->header('Paymongo-Signature'), $raw, $livemode)) {
            Log::warning('PayMongo webhook rejected: invalid signature.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        // A test-mode payment must never count on a live system (or the
        // reverse). Acknowledged so PayMongo stops retrying, but ignored.
        if ($livemode !== PayMongo::liveMode()) {
            Log::warning('PayMongo webhook ignored: '.($livemode ? 'live' : 'test').' event but the app uses '.(PayMongo::liveMode() ? 'live' : 'test').' keys.');

            return response()->json(['received' => true, 'ignored' => 'mode mismatch']);
        }

        $eventId = (string) data_get($event, 'data.id');
        $type = (string) data_get($event, 'data.attributes.type');

        try {
            DB::transaction(function () use ($eventId, $type, $event, $paymongo) {
                // Inserted first so a concurrent retry of the same event hits
                // the unique index and stops here.
                DB::table('webhook_events')->insert([
                    'provider' => 'paymongo', 'event_id' => $eventId, 'type' => $type,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                if ($type === 'checkout_session.payment.paid') {
                    $session = (array) data_get($event, 'data.attributes.data', []);
                    $invoice = Invoice::where('checkout_session_id', data_get($session, 'id'))->first()
                        ?? Invoice::find(data_get($session, 'attributes.metadata.invoice_id'));

                    if ($invoice) {
                        $paymongo->recordPaidSession($invoice, $session);
                    } else {
                        Log::warning('PayMongo webhook for unknown checkout session '.data_get($session, 'id'));
                    }
                }
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        return response()->json(['received' => true]);
    }
}
