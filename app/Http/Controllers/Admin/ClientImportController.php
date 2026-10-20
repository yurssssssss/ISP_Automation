<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRouterAccess;
use App\Models\Plan;
use App\Services\ClientImport;
use App\Services\ClientOnboarding;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Many customers at once from a spreadsheet (ClientImport). */
class ClientImportController extends Controller
{
    /** Shown on the page; more than this goes to "and N more". */
    private const ERRORS_SHOWN = 50;

    public function show(): View
    {
        return view('admin.clients-import', [
            'plans' => Plan::where('is_active', true)->orderBy('price')->get(),
            'maxRows' => ClientImport::MAX_ROWS,
        ]);
    }

    public function template(): StreamedResponse
    {
        $plan = Plan::where('is_active', true)->orderBy('price')->first();
        $planName = $plan?->name ?? 'Fiber 20';
        $due = today()->addMonthNoOverflow()->toDateString();

        return response()->streamDownload(function () use ($planName, $due) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ClientImport::COLUMNS, escape: '\\');
            fputcsv($out, ['Juan Dela Cruz', 'juan@example.com', '09171234567', '12 Rizal St., Brgy. 1', $planName, 'pppoe', 'juan.delacruz', 'pppoe-pass-1', $due, 'automatic'], escape: '\\');
            fputcsv($out, ['Ana Reyes', 'ana@example.com', '', '', $planName, 'pppoe', 'ana.reyes', 'pppoe-pass-2', $due, 'manual'], escape: '\\');
            fclose($out);
        }, 'clients-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, ClientImport $import, ClientOnboarding $onboarding, RouterGateway $router): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'create_missing' => ['nullable', 'boolean'],
        ], ['file.mimes' => 'Upload a .csv file (in Excel: File → Save As → CSV UTF-8).']);

        $result = $import->read($request->file('file')->getRealPath());

        if ($result['errors']) {
            return back()->with('importErrors', array_slice($result['errors'], 0, self::ERRORS_SHOWN))
                ->with('importErrorCount', count($result['errors']));
        }
        if (! $result['rows']) {
            return back()->with('error', 'The file has no customers in it, only the column names.');
        }

        $subscriptions = $import->import($result['rows'], $onboarding);
        $first = $subscriptions[0]->client->account_no;
        $last = end($subscriptions)->client->account_no;
        $message = count($subscriptions).' '.str('client')->plural(count($subscriptions))." imported ({$first} – {$last}). They set their portal password with \"Forgot password\" using their email.";

        if ($request->boolean('create_missing')) {
            if ($router->isConfigured()) {
                foreach ($subscriptions as $subscription) {
                    SyncRouterAccess::dispatch($subscription, createIfMissing: true);
                }
                $message .= ' PPPoE accounts the router doesn\'t have yet are being created in the background (see Router activity).';
            } else {
                $message .= ' The router isn\'t set up yet: run "mikrotik:sync --create-missing" once it is.';
            }
        }

        return redirect()->route('admin.dashboard')->with('status', $message);
    }
}
