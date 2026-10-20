<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\ClientOnboarding;
use App\Services\MikroTik\RouterGateway;
use App\Services\MikroTik\RouterOsException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function create()
    {
        return view('admin.clients-create', ['plans' => Plan::where('is_active', true)->orderBy('price')->get()]);
    }

    public function store(Request $request, RouterGateway $router, ClientOnboarding $onboarding)
    {
        $data = $request->validate(ClientOnboarding::rules() + [
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'create_on_router' => ['nullable', 'boolean'],
        ]);
        $data['email'] = Str::lower(trim($data['email']));

        $subscription = $onboarding->open($data);

        $message = "Client {$subscription->client->account_no} added. They set their portal password with \"Forgot password\" using {$data['email']}.";

        if ($request->boolean('create_on_router') && ! $router->isConfigured()) {
            $message .= ' The router isn\'t set up yet, so the PPPoE account will be created when you connect it (mikrotik:sync --create-missing).';
        } elseif ($request->boolean('create_on_router')) {
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
