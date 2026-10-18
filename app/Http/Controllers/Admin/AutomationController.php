<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Automation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * "Run checks now": the same billing tasks the scheduler runs each night,
 * on demand — after fixing a due date, or to try the automation out.
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
}
