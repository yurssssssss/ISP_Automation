<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use App\Support\Automation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * "Run checks now": the same billing tasks the scheduler runs each night,
 * on demand — after fixing a due date, or to try the automation out.
 * "Test router": logs in to the MikroTik once and reports what happened.
 */
class AutomationController extends Controller
{
    public function run(): RedirectResponse
    {
        $summaries = [];

        foreach (Automation::runNowCommands() as $command) {
            Artisan::call($command);
            $summaries[] = trim(Artisan::output());
        }

        return back()->with('status', 'Billing checks finished. '.implode(' ', $summaries));
    }

    public function testRouter(RouterGateway $router): RedirectResponse
    {
        if (! $router->isConfigured()) {
            return back()->with('error', 'The router isn\'t set up yet: fill in MIKROTIK_HOST, MIKROTIK_USERNAME and MIKROTIK_PASSWORD, then restart the app.');
        }

        $host = config('isp.mikrotik.host').':'.config('isp.mikrotik.port');

        try {
            $message = "Connected to router \"{$router->identity()}\" at {$host}.";
            Automation::recordRouterCheck(true, $message);

            return back()->with('status', $message);
        } catch (RouterOsException $e) {
            $message = "Couldn't connect to the router at {$host}: {$e->getMessage()}";
            Automation::recordRouterCheck(false, $message);

            return back()->with('error', $message);
        }
    }
}
