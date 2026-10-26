<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_plan_leaves_the_website_and_new_client_forms_but_keeps_its_customers(): void
    {
        $plan = Plan::factory()->create(['name' => 'Old Promo']);
        $sub = Subscription::factory()->for($plan)->create();
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('admin.plans.visibility', $plan), ['is_active' => 0])
            ->assertSessionHas('status', 'Old Promo is hidden. Customers already on it keep it.');

        $this->get('/')->assertDontSee('Old Promo');
        $this->get(route('admin.clients.create'))->assertDontSee('Old Promo');
        $this->assertSame($plan->id, $sub->fresh()->plan_id);

        $this->post(route('admin.plans.visibility', $plan), ['is_active' => 1]);
        $this->get('/')->assertSee('Old Promo');
    }

    public function test_customers_cannot_change_plan_visibility(): void
    {
        $plan = Plan::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.plans.visibility', $plan), ['is_active' => 0])
            ->assertForbidden();

        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_a_live_system_starts_without_sample_plans(): void
    {
        $this->app['env'] = 'production';
        config(['isp.admin' => ['email' => 'owner@example.com', 'password' => 'Strong#Pass1']]);

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
    }
}
