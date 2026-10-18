<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Admin login from ADMIN_EMAIL / ADMIN_PASSWORD in .env, three starter
     * plans (their profile names must exist in /ppp/profile on the router),
     * and — locally only — one demo subscriber whose PPPoE username is
     * MIKROTIK_TEST_USER, for trying suspend/restore against a real router.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (! $password) {
            $this->command->error('Set ADMIN_PASSWORD in .env first.');

            return;
        }

        User::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@isp.test')], [
            'name' => 'Administrator',
            'password' => $password,
            'role' => User::ADMIN,
            'email_verified_at' => now(),
        ]);

        foreach ([['Fiber 20', 20, 999, '20M'], ['Fiber 50', 50, 1499, '50M'], ['Fiber 100', 100, 1999, '100M']] as [$name, $speed, $price, $profile]) {
            Plan::firstOrCreate(['name' => $name], ['speed_mbps' => $speed, 'price' => $price, 'mikrotik_profile' => $profile]);
        }

        if (app()->isLocal() && ($username = env('MIKROTIK_TEST_USER'))) {
            $client = Client::firstOrCreate(['account_no' => 'ACC-DEMO1'], [
                'name' => 'Demo Subscriber',
                'email' => env('DEMO_CLIENT_EMAIL'),
            ]);
            $client->subscriptions()->firstOrCreate(['username' => $username], [
                'plan_id' => Plan::where('name', 'Fiber 20')->value('id'),
                'service_type' => 'pppoe',
                'next_due_date' => today()->addDays(3),
            ]);
            $this->command->info("Demo subscription for PPPoE user \"{$username}\" (due in 3 days).");
        }
    }
}
