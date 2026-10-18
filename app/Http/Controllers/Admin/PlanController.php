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
}
