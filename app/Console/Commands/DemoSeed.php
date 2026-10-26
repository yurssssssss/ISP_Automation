<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\RouterLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ClientOnboarding;
use App\Support\Demo;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Signature('demo:seed {--fresh : Delete every row first (demo databases only)}')]
#[Description('Fill a demo database with sample plans, customers, bills and payments (DEMO_MODE=true only)')]
class DemoSeed extends Command
{
    /**
     * [name, plan index, scenario]. Scenarios show every state the dashboard
     * and portal can be in.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    private const CUSTOMERS = [
        ['Juan Dela Cruz', 0, 'due-soon'],
        ['Maria Santos', 1, 'paid'],
        ['Jose Reyes', 0, 'suspended'],
        ['Ana Mendoza', 1, 'due-today'],
        ['Pedro Garcia', 2, 'reconnected'],
        ['Liza Bautista', 0, 'manual-overdue'],
        ['Carlo Villanueva', 1, 'kept-on'],
        ['Grace Ramos', 0, 'partial'],
        ['Mark Aquino', 1, 'cancelled'],
        ['Rosa Flores', 2, 'paid'],
        ['Ben Cruz', 0, 'suspended'],
        ['Joy Navarro', 1, 'billed'],
    ];

    public function handle(ClientOnboarding $onboarding): int
    {
        if (! Demo::enabled()) {
            $this->error('Refusing to run: set DEMO_MODE=true. This command fills the database with sample data.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->wipe();
        }

        if (User::where('email', Demo::ADMIN_EMAIL)->exists()) {
            $this->warn('Demo data is already there. Use --fresh to start over.');

            return self::SUCCESS;
        }

        User::forceCreate([
            'name' => 'Demo Administrator', 'email' => Demo::ADMIN_EMAIL, 'password' => Demo::ADMIN_PASSWORD,
            'role' => User::ADMIN, 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $plans = [
            Plan::create(['name' => 'Fiber 25', 'speed_mbps' => 25, 'price' => 999, 'mikrotik_profile' => '25MBPS']),
            Plan::create(['name' => 'Fiber 50', 'speed_mbps' => 50, 'price' => 1299, 'mikrotik_profile' => '50MBPS']),
            Plan::create(['name' => 'Fiber 100', 'speed_mbps' => 100, 'price' => 1699, 'mikrotik_profile' => '100MBPS']),
        ];

        foreach (self::CUSTOMERS as $i => [$name, $planIndex, $scenario]) {
            $first = Str::lower(Str::before($name, ' '));
            $email = $i === 0 ? Demo::CLIENT_EMAIL : $first.'.'.Str::lower(Str::afterLast($name, ' ')).'@demo.test';

            $subscription = $onboarding->open([
                'name' => $name,
                'email' => $email,
                'phone' => '0917'.str_pad((string) (1234567 + $i * 1111), 7, '0', STR_PAD_LEFT),
                'address' => ($i + 3).' Rizal St., Brgy. '.($i % 4 + 1),
                'plan_id' => $plans[$planIndex]->id,
                'service_type' => 'pppoe',
                'username' => $first.'.'.Str::lower(Str::afterLast($name, ' ')),
                'service_password' => Str::random(12),
                'next_due_date' => today()->toDateString(),
            ]);

            $user = $subscription->client->user;
            $user->forceFill(['email_verified_at' => now()] + ($i === 0 ? ['password' => Demo::CLIENT_PASSWORD] : []))->save();

            $this->applyScenario($subscription->fresh(['plan', 'client']), $scenario);
        }

        // Run the real nightly tasks once, so the dashboard shows genuine results.
        foreach (['billing:issue-invoices', 'billing:send-reminders', 'billing:suspend-overdue'] as $command) {
            $this->callSilently($command);
        }

        $this->info('Demo data ready: '.count(self::CUSTOMERS).' customers.');
        $this->line('Admin:    '.Demo::ADMIN_EMAIL.' / '.Demo::ADMIN_PASSWORD);
        $this->line('Customer: '.Demo::CLIENT_EMAIL.' / '.Demo::CLIENT_PASSWORD);

        return self::SUCCESS;
    }

    /**
     * Rows only, tables stay: quick enough for a serverless request, where
     * dropping and re-creating every table on a remote database is not.
     */
    private function wipe(): void
    {
        $tables = array_diff(Schema::getTableListing(schemaQualified: false), ['migrations']);

        Schema::withoutForeignKeyConstraints(function () use ($tables): void {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        });
    }

    private function applyScenario(Subscription $s, string $scenario): void
    {
        $price = (float) $s->plan->price;

        switch ($scenario) {
            case 'paid':
            case 'reconnected':
                $this->paidHistory($s, today()->addDays(18 - $s->id % 6), 2);
                if ($scenario === 'reconnected') {
                    $this->routerLog($s, 'disable', 'Account disabled, 1 session(s) dropped', 9);
                    $this->routerLog($s, 'enable', 'Account enabled', 8);
                }
                break;

            case 'due-soon':
                $due = today()->addDays(3);
                $this->paidHistory($s, $due, 2);
                $this->openBill($s, $due, reminded: true);
                break;

            case 'due-today':
                $this->paidHistory($s, today(), 1);
                $this->openBill($s, today());
                break;

            case 'billed':
                $due = today()->addDays(6);
                $this->paidHistory($s, $due, 1);
                $this->openBill($s, $due);
                break;

            case 'partial':
                $due = today()->addDays(5);
                $this->paidHistory($s, $due, 1);
                $bill = $this->openBill($s, $due);
                $bill->update(['amount_paid' => 500, 'status' => Invoice::PARTIAL]);
                $bill->payments()->create(['amount' => 500, 'method' => 'cash', 'gateway' => 'manual', 'paid_at' => now()->subDays(2)]);
                break;

            case 'suspended':
                $due = today()->subDays(4 + $s->id % 6);
                $this->paidHistory($s, $due, 1);
                $this->openBill($s, $due, reminded: true);
                $s->update(['status' => Subscription::SUSPENDED, 'suspended_at' => $due->copy()->addDay()]);
                $s->client->update(['status' => 'suspended']);
                $this->routerLog($s, 'disable', 'Account disabled, 1 session(s) dropped', (int) $due->diffInDays(today(), true) - 1);
                break;

            case 'manual-overdue':
                $due = today()->subDays(6);
                $this->paidHistory($s, $due, 1);
                $this->openBill($s, $due, reminded: true);
                $s->update(['auto_suspend' => false]);
                break;

            case 'kept-on':
                $due = today()->subDays(2);
                $this->paidHistory($s, $due, 1);
                $this->openBill($s, $due, reminded: true);
                $s->update(['suspension_hold_until' => today()->addDays(3)]);
                break;

            case 'cancelled':
                $this->paidHistory($s, today()->subDays(20), 2);
                $s->update(['status' => Subscription::CANCELLED]);
                $s->client->update(['status' => 'inactive']);
                $this->routerLog($s, 'disable', 'Account disabled', 12);
                break;
        }
    }

    /** $months paid monthly bills before $nextDue; the subscription is due on $nextDue. */
    private function paidHistory(Subscription $s, CarbonInterface $nextDue, int $months): void
    {
        $nextDue = $nextDue->copy()->startOfDay();
        for ($m = $months; $m >= 1; $m--) {
            $due = $nextDue->copy()->subMonthsNoOverflow($m);
            $paidAt = $due->copy()->subDays(1 + $m)->setTime(10 + $m, 15);
            $bill = $s->invoices()->create([
                'due_date' => $due, 'period_start' => $due, 'period_end' => $due->copy()->addMonthNoOverflow()->subDay(),
                'amount' => $s->plan->price, 'amount_paid' => $s->plan->price, 'status' => Invoice::PAID, 'paid_at' => $paidAt,
            ]);
            $bill->payments()->create([
                'amount' => $s->plan->price, 'method' => $m % 2 ? 'gcash' : 'cash', 'gateway' => $m % 2 ? 'paymongo' : 'manual',
                'reference' => $m % 2 ? 'demo_pay_'.$s->id.'_'.$m : null, 'paid_at' => $paidAt,
            ]);
        }
        $s->update(['next_due_date' => $nextDue]);
    }

    private function openBill(Subscription $s, CarbonInterface $due, bool $reminded = false): Invoice
    {
        $due = $due->copy()->startOfDay();

        return $s->invoices()->create([
            'due_date' => $due, 'period_start' => $due, 'period_end' => $due->copy()->addMonthNoOverflow()->subDay(),
            'amount' => $s->plan->price, 'amount_paid' => 0, 'status' => Invoice::UNPAID,
            'reminded_at' => $reminded ? $due->copy()->subDays(3)->setTime(8, 0) : null,
        ]);
    }

    private function routerLog(Subscription $s, string $action, string $message, int $daysAgo): void
    {
        $log = RouterLog::create(['subscription_id' => $s->id, 'username' => $s->username, 'action' => $action, 'success' => true, 'message' => $message]);
        $log->forceFill(['created_at' => now()->subDays(max(0, $daysAgo))->setTime(0, 1)])->save();
    }
}
