<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function create()
    {
        return view('admin.clients-create', ['plans' => Plan::where('is_active', true)->orderBy('price')->get()]);
    }

    public function store(Request $request, RouterGateway $router)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'service_type' => ['required', Rule::in(['pppoe', 'hotspot'])],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/', 'unique:subscriptions,username'],
            'service_password' => ['required', 'string', 'min:6', 'max:64'],
            'next_due_date' => ['required', 'date', 'after_or_equal:today'],
            'create_on_router' => ['nullable', 'boolean'],
        ]);
        $data['email'] = Str::lower(trim($data['email']));

        $subscription = DB::transaction(function () use ($data) {
            // Random portal password nobody knows; the client sets their own
            // with "Forgot password", which also verifies their email.
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(40),
                'role' => User::CLIENT,
            ]);

            $client = Client::openAccount([
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            return $client->subscriptions()->create([
                'plan_id' => $data['plan_id'],
                'service_type' => $data['service_type'],
                'username' => $data['username'],
                'password' => $data['service_password'],
                'next_due_date' => $data['next_due_date'],
            ]);
        });

        $message = "Client {$subscription->client->account_no} added. They set their portal password with \"Forgot password\" using {$data['email']}.";

        if ($request->boolean('create_on_router')) {
            try {
                if ($router->findAccount($subscription->username, $subscription->service_type)) {
                    $message .= ' The router already had this account, so it was left as is.';
                } else {
                    $router->createAccount($subscription->username, $data['service_password'], $subscription->plan->mikrotik_profile,
                        $subscription->service_type, $subscription->client->account_no.' '.$subscription->client->name);
                    $message .= ' Router account created.';
                }
            } catch (RouterOsException $e) {
                return redirect()->route('admin.dashboard')->with('status', $message)
                    ->with('error', 'Could not create the router account: '.$e->getMessage());
            }
        }

        return redirect()->route('admin.dashboard')->with('status', $message);
    }
}
