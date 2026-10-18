<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_active_plans_cheapest_first(): void
    {
        Plan::factory()->create(['name' => 'Fiber 100', 'speed_mbps' => 100, 'price' => 1999]);
        Plan::factory()->create(['name' => 'Fiber 20', 'speed_mbps' => 20, 'price' => 999]);
        Plan::factory()->create(['name' => 'Old Plan', 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Fiber 20', '₱999.00', 'Fiber 100', '₱1,999.00'])
            ->assertSee('Speeds up to')
            ->assertDontSee('Old Plan')
            ->assertSee(route('login'));
    }

    public function test_fastest_plan_is_highlighted_only_when_there_is_a_choice(): void
    {
        Plan::factory()->create(['name' => 'Fiber 20', 'speed_mbps' => 20, 'price' => 999]);
        $this->get('/')->assertDontSee('plan-ribbon');

        Plan::factory()->create(['name' => 'Fiber 100', 'speed_mbps' => 100, 'price' => 1999]);
        $this->get('/')->assertSeeInOrder(['Fiber 20', 'plan-ribbon', 'Fastest', 'Fiber 100'], false);
    }

    public function test_billing_timeline_follows_the_billing_settings(): void
    {
        config(['isp.invoice_lead_days' => 7, 'isp.reminder_days' => 1, 'isp.grace_days' => 0]);
        $this->get('/')->assertSeeInOrder(['7 days before', '1 day before', 'By the due date', 'Day after the due date', 'Service pauses if still unpaid']);

        config(['isp.grace_days' => 3]);
        $this->get('/')->assertSeeInOrder(['By the due date', 'After a 3-day grace period', 'Service pauses if still unpaid']);
    }

    public function test_page_says_plans_are_coming_when_there_are_none(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Our plans will be posted here soon.')
            ->assertDontSee('Speeds up to');
    }

    public function test_plan_names_are_escaped(): void
    {
        Plan::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->get('/')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_online_payment_methods_are_listed_only_when_paymongo_is_set_up(): void
    {
        config(['services.paymongo.secret_key' => null]);
        $this->get('/')->assertSee('Pay in cash at our office.')->assertDontSee('GCash');

        config(['services.paymongo.secret_key' => 'sk_test_x', 'services.paymongo.methods' => ['qrph', 'gcash', 'paymaya']]);
        $this->get('/')->assertSee('QR Ph, GCash or Maya');
    }

    public function test_only_configured_contact_details_are_shown(): void
    {
        config(['isp.contact' => ['phone' => '0917 123 4567', 'email' => null, 'address' => null, 'hours' => 'Mon–Sat 8am–5pm']]);

        $this->get('/')
            ->assertSee('tel:09171234567', false)
            ->assertSee('Mon–Sat 8am–5pm')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('Visit us');
    }

    public function test_page_says_contact_details_are_coming_when_none_are_set(): void
    {
        config(['isp.contact' => ['phone' => null, 'email' => null, 'address' => null, 'hours' => null]]);

        $this->get('/')->assertSee('Our contact details will be posted here soon.');
    }

    public function test_signed_in_users_can_open_it_and_get_a_link_to_their_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('My account')
            ->assertDontSee('>Sign in</a>', false);
    }
}
