<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PayMongo;
use Illuminate\View\View;

/**
 * The public front page: active plans with prices, how billing works, how
 * to get connected, and the office's contact details (config/isp.php).
 */
class LandingController extends Controller
{
    /** PayMongo method codes (PAYMONGO_METHODS) → names customers know. */
    private const METHOD_LABELS = [
        'qrph' => 'QR Ph',
        'gcash' => 'GCash',
        'paymaya' => 'Maya',
        'card' => 'Credit / debit card',
        'grab_pay' => 'GrabPay',
        'dob' => 'Online banking',
        'dob_ubp' => 'Online banking',
    ];

    public function __invoke(): View
    {
        $paymentMethods = PayMongo::enabled()
            ? collect(config('services.paymongo.methods'))
                ->map(fn (string $method): ?string => self::METHOD_LABELS[$method] ?? null)
                ->filter()
                ->unique()
                ->values()
            : collect();

        $plans = Plan::where('is_active', true)->orderBy('price')->get();

        return view('landing', [
            'plans' => $plans,
            // Highlighted only when there is a choice to make.
            'fastestPlanId' => $plans->count() > 1 ? $plans->sortByDesc('speed_mbps')->first()->id : null,
            'paymentMethods' => $paymentMethods,
            'contact' => array_filter(config('isp.contact')),
            'invoiceLeadDays' => config('isp.invoice_lead_days'),
            'reminderDays' => config('isp.reminder_days'),
            'graceDays' => config('isp.grace_days'),
        ]);
    }
}
