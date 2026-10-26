<?php

namespace App\Services;

use App\Mail\ClientWelcome;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Opens a new customer: their portal login, client record (with an
 * ACC-00001 account number) and internet subscription, in one transaction.
 * Shared by the admin's "Add client" form and the CSV import.
 */
class ClientOnboarding
{
    /**
     * Rules for one customer, apart from the plan (the form sends a plan id,
     * the import a plan name).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'service_type' => ['required', Rule::in(['pppoe', 'hotspot'])],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/', 'unique:subscriptions,username'],
            'service_password' => ['required', 'string', 'min:6', 'max:64'],
            'next_due_date' => ['required', 'date', 'after_or_equal:today'],
            'auto_suspend' => ['nullable', 'boolean'],
        ];
    }

    /** Queues the "your account is ready" email. A mail problem never blocks adding the customer. */
    public function sendWelcome(Subscription $subscription): void
    {
        $user = $subscription->client->user;

        try {
            Mail::to($user->email, $user->name)->queue(new ClientWelcome($subscription));
        } catch (\Throwable $e) {
            Log::error('Welcome email failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string, address?: ?string, plan_id: int, service_type: string, username: string, service_password: string, next_due_date: string, auto_suspend?: ?bool}  $data
     */
    public function open(array $data): Subscription
    {
        $email = Str::lower(trim($data['email']));

        return DB::transaction(function () use ($data, $email) {
            // Random portal password nobody knows; the client sets their own
            // with "Forgot password", which also verifies their email.
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => Str::random(40),
                'role' => User::CLIENT,
            ]);

            $client = Client::openAccount([
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            return $client->subscriptions()->create([
                'plan_id' => $data['plan_id'],
                'service_type' => $data['service_type'],
                'username' => $data['username'],
                'password' => $data['service_password'],
                'next_due_date' => $data['next_due_date'],
                'auto_suspend' => $data['auto_suspend'] ?? true,
            ]);
        });
    }
}
