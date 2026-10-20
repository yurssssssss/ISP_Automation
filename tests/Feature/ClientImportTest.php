<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Fakes\FakeRouter;
use Tests\TestCase;

class ClientImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "name,email,phone,address,plan,service,username,password,next_due_date,cutoff\n";

    private FakeRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new FakeRouter;
        $this->app->instance(RouterGateway::class, $this->router);
        $this->travelTo(now()->setDate(2026, 10, 20));
        Plan::factory()->create(['name' => 'Fiber 20', 'mikrotik_profile' => '20MBPS']);
        Plan::factory()->create(['name' => 'Fiber 50', 'mikrotik_profile' => '50MBPS']);
        $this->actingAs(User::factory()->admin()->create());
    }

    private function upload(string $csv, array $extra = [])
    {
        return $this->from(route('admin.clients.import'))
            ->post(route('admin.clients.import.store'), ['file' => UploadedFile::fake()->createWithContent('clients.csv', $csv)] + $extra);
    }

    public function test_valid_file_imports_every_customer(): void
    {
        $this->upload(self::HEADER
            ."Juan Dela Cruz,Juan@Example.com,0917,12 Rizal St,Fiber 20,pppoe,juan.dc,secret-1,2026-11-15,automatic\n"
            ."Ana Reyes,ana@example.com,,,50mbps,,ana.r,secret-2,11/5/2026,manual\n"
            ."\n")
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', fn (string $s) => str_starts_with($s, '2 clients imported (ACC-00001 – ACC-00002).'));

        $juan = Subscription::where('username', 'juan.dc')->with('client.user', 'plan')->firstOrFail();
        $this->assertSame('juan@example.com', $juan->client->user->email);
        $this->assertSame('Fiber 20', $juan->plan->name);
        $this->assertSame('secret-1', $juan->password);
        $this->assertTrue($juan->auto_suspend);
        $this->assertSame(User::CLIENT, $juan->client->user->role);

        $ana = Subscription::where('username', 'ana.r')->with('plan')->firstOrFail();
        $this->assertSame('Fiber 50', $ana->plan->name, 'plan matched by MikroTik profile, any case');
        $this->assertSame('pppoe', $ana->service_type);
        $this->assertSame('2026-11-05', $ana->next_due_date->toDateString());
        $this->assertFalse($ana->auto_suspend);
    }

    public function test_excel_byte_order_mark_is_ignored(): void
    {
        $this->upload("\xEF\xBB\xBF".self::HEADER."Juan,juan@example.com,,,Fiber 20,pppoe,juan,secret-1,2026-11-15,\n")
            ->assertSessionHas('status');

        $this->assertDatabaseHas('subscriptions', ['username' => 'juan']);
    }

    public function test_one_bad_row_imports_nothing_and_lists_every_problem(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->upload(self::HEADER
            ."Good Row,good@example.com,,,Fiber 20,pppoe,good,secret-1,2026-11-15,automatic\n"
            ."Bad Plan,bad@example.com,,,Fiber 999,pppoe,badplan,secret-2,2026-11-15,automatic\n"
            ."Taken,taken@example.com,,,Fiber 20,pppoe,taken,secret-3,15-11-2026,sometimes\n"
            ."Dupe,dupe@example.com,,,Fiber 20,pppoe,GOOD,123,2026-11-15,automatic\n")
            ->assertRedirect(route('admin.clients.import'))
            ->assertSessionHas('importErrorCount', 3)
            ->assertSessionHas('importErrors', function (array $errors) {
                $byLine = collect($errors)->pluck('message', 'line');

                return str_contains($byLine[3], 'Unknown plan "Fiber 999"')
                    && stripos($byLine[4], 'cutoff must be "automatic" or "manual"') !== false
                    && stripos($byLine[4], 'isn\'t a date') !== false
                    && stripos($byLine[4], 'the email has already been taken') !== false
                    && stripos($byLine[5], 'same username as line 2') !== false
                    && stripos($byLine[5], 'the password field must be at least 6 characters') !== false;
            });

        $this->assertSame(0, Client::count(), 'nothing imported, not even the good row');
    }

    public function test_due_date_in_the_past_is_rejected(): void
    {
        $this->upload(self::HEADER."Late,late@example.com,,,Fiber 20,pppoe,late,secret-1,2026-10-01,automatic\n")
            ->assertSessionHas('importErrors', fn (array $errors) => str_contains($errors[0]['message'], 'next due date field must be a date after or equal to today'));
    }

    public function test_missing_columns_are_reported(): void
    {
        $this->upload("name,email\nJuan,juan@example.com\n")
            ->assertSessionHas('importErrors', fn (array $errors) => $errors[0]['line'] === 1
                && str_contains($errors[0]['message'], 'Missing column(s): plan, username, password, next_due_date'));
    }

    public function test_router_accounts_can_be_created_for_the_imported_customers(): void
    {
        $this->router->addAccount('existing', '20MBPS');

        $this->upload(self::HEADER
            ."New,new@example.com,,,Fiber 20,pppoe,brand.new,secret-1,2026-11-15,automatic\n"
            ."Old,old@example.com,,,Fiber 20,pppoe,existing,secret-2,2026-11-15,automatic\n", ['create_missing' => '1'])
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'being created in the background'));

        $this->assertSame('secret-1', $this->router->passwords['brand.new']);
        $this->assertSame('20MBPS', $this->router->accounts['brand.new']['profile']);
        $this->assertArrayNotHasKey('existing', $this->router->passwords, 'an account already on the router is left alone');
    }

    public function test_without_a_router_the_import_says_how_to_create_accounts_later(): void
    {
        $this->router->configured = false;

        $this->upload(self::HEADER."New,new@example.com,,,Fiber 20,pppoe,brand.new,secret-1,2026-11-15,automatic\n", ['create_missing' => '1'])
            ->assertSessionHas('status', fn (string $s) => str_contains($s, "router isn't set up yet"));

        $this->assertSame([], $this->router->calls);
    }

    public function test_template_has_every_column(): void
    {
        $csv = $this->get(route('admin.clients.import.template'))->assertOk()->streamedContent();

        $this->assertStringStartsWith(rtrim(self::HEADER), $csv);
        $this->assertStringContainsString('Fiber 20', $csv);
    }

    public function test_customers_cannot_import(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.clients.import.store'), ['file' => UploadedFile::fake()->createWithContent('c.csv', self::HEADER)])
            ->assertForbidden();
    }
}
