<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('admin.plans', ['plans' => Plan::withCount('subscriptions')->orderBy('price')->get()]);
    }

    public function store(Request $request)
    {
        Plan::create($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'speed_mbps' => ['required', 'integer', 'min:1', 'max:10000'],
            'price' => ['required', 'numeric', 'min:1', 'max:999999'],
            'mikrotik_profile' => ['required', 'string', 'max:100'],
        ]));

        return back()->with('status', 'Plan added.');
    }

    /**
     * Hidden plans leave the public site, Add client and the CSV import, but
     * keep working for customers already on them. Plans can't be deleted
     * while anyone uses them (the database refuses), so this is how an old
     * or test plan is retired.
     */
    public function visibility(Request $request, Plan $plan)
    {
        $plan->update(['is_active' => $request->validate(['is_active' => ['required', 'boolean']])['is_active']]);

        return back()->with('status', $plan->is_active
            ? "{$plan->name} is shown on the website and can be chosen for new clients."
            : "{$plan->name} is hidden. Customers already on it keep it.");
    }
}
