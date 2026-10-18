<?php

use App\Support\Automation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Daily billing automation. Needs two background processes:
|   - the scheduler: `php artisan schedule:work` (or on a server, one cron
|     entry: * * * * * cd /path/to/ISP_automation && php artisan schedule:run)
|   - a queue worker: `php artisan queue:work` (router changes and emails)
| `composer run dev` starts both locally; Docker runs them as services.
|
| The tasks and their times live in App\Support\Automation::TASKS so the
| admin dashboard shows the same schedule:
|   00:00 cut accounts still unpaid after their due date (+ isp.grace_days)
|   00:10 create next period's bills (isp.invoice_lead_days ahead) and email them
|   01:00 make the router match the database (fixes missed or manual changes)
|   08:00 "due in a few days" reminders (isp.reminder_days)
*/
foreach (Automation::TASKS as $command => $task) {
    Schedule::command($command)->dailyAt($task['at'])->withoutOverlapping();
}

// Lets the dashboard tell whether the scheduler is running.
Schedule::call(fn () => Automation::heartbeat())->everyMinute()->name('automation:heartbeat');

// Expired one-time codes.
Schedule::call(fn () => DB::table('email_codes')->where('expires_at', '<', now())->delete())->hourly()->name('email-codes:prune');
