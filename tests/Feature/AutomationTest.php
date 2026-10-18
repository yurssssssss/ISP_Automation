<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing;
use App\Support\Automation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_tasks_are_scheduled_daily_at_their_times(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('0  0 * * *  php artisan billing:suspend-overdue')
            ->expectsOutputToContain('10 0 * * *  php artisan billing:issue-invoices')
            ->expectsOutputToContain('0  1 * * *  php artisan mikrotik:sync')
            ->expectsOutputToContain('0  8 * * *  php artisan billing:send-reminders')
            ->expectsOutputToContain('automation:heartbeat');
    }

    public function test_each_run_is_recorded_and_shown_on_the_dashboard(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(0, 0));
        $this->artisan('billing:suspend-overdue');

        $task = collect(Automation::status()['tasks'])->firstWhere('command', 'billing:suspend-overdue');
        $this->assertSame('Suspended 0 subscription(s).', $task['summary']);
        $this->assertTrue($task['lastRun']->equalTo(now()));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertSeeInOrder(['Cut accounts unpaid after the due date', 'Suspended 0 subscription(s).']);
    }

    public function test_admin_can_run_the_checks_now(): void
    {
        Queue::fake();
        Mail::fake();
        $overdue = Subscription::factory()->create(['next_due_date' => today()->subDays(2)]);
        app(Billing::class)->issueInvoice($overdue);
        $upcoming = Subscription::factory()->create(['next_due_date' => today()->addDays(3)]);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.dashboard'))
            ->post(route('admin.automation.run'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'Billing checks finished. Issued 1 invoice(s). Queued 1 reminder(s). Suspended 1 subscription(s).');

        $this->assertSame('suspended', $overdue->fresh()->status);
        $this->assertSame(1, $upcoming->invoices()->count());
    }

    public function test_customers_cannot_run_the_checks(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.automation.run'))
            ->assertForbidden();
    }

    public function test_dashboard_warns_when_the_scheduler_is_not_running(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee('Scheduler: not running')
            ->assertSee("The scheduler isn't running", false);

        Automation::heartbeat();
        $this->get(route('admin.dashboard'))->assertSee('Scheduler: running')->assertDontSee("The scheduler isn't running", false);

        $this->travel(Automation::SCHEDULER_SILENT_AFTER_SECONDS + 1)->seconds();
        $this->get(route('admin.dashboard'))->assertSee('Scheduler: not running');
    }

    public function test_dashboard_warns_when_router_changes_are_left_waiting(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subMinutes(5)->getTimestamp(), 'created_at' => now()->subMinutes(5)->getTimestamp(),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertSee('Worker: 1 waiting')
            ->assertSee('1 router change and emails are waiting.');
    }

    public function test_a_fresh_job_is_not_reported_as_stuck(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
        ]);

        $this->assertFalse(Automation::status()['queue']['stuck']);
    }
}
