<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
| Daily billing automation. Needs one cron entry on the server:
|   * * * * * cd /path/to/ISP_automation && php artisan schedule:run
| (locally: `php artisan schedule:work`), plus a queue worker
| (`php artisan queue:work`) for router changes and emails.
*/

// Cut overdue accounts at midnight (due date + isp.grace_days).
Schedule::command('billing:suspend-overdue')->dailyAt('00:00')->withoutOverlapping();

// Issue next period's invoices (isp.invoice_lead_days ahead).
Schedule::command('billing:issue-invoices')->dailyAt('00:10')->withoutOverlapping();

// Reconcile: fix anything the router missed (it was offline, someone
// changed an account by hand in Winbox, ...).
Schedule::command('mikrotik:sync')->dailyAt('01:00')->withoutOverlapping();

// "Due in 3 days" emails, at a civil hour.
Schedule::command('billing:send-reminders')->dailyAt('08:00')->withoutOverlapping();

// Expired one-time codes.
Schedule::call(fn () => DB::table('email_codes')->where('expires_at', '<', now())->delete())->hourly();
