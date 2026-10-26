@extends('layouts.admin')
@section('title', 'Add client')

@section('content')
<div class="page-head">
    <div>
        <h1>Add client</h1>
        <p>Creates their portal login and internet account. They set their own password with "Forgot password".</p>
    </div>
</div>

@if ($plans->isEmpty())
    <div class="alert alert-warning rounded-4 border-0">Add a plan first on the <a href="{{ route('admin.plans.index') }}">Plans</a> page.</div>
@endif

<form method="POST" action="{{ route('admin.clients.store') }}">
    @csrf
    <div class="row g-4">
        <div class="col-xl-8">
            <section class="panel form-section">
                <div class="panel-head"><div><h2>Client details</h2><p>The email becomes their portal login.</p></div></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="name">Full name</label>
                            <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="email">Email (portal login)</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required
                                   data-email-check="new" data-email-check-url="{{ route('email.check') }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="phone">Mobile <span class="text-body-secondary fw-normal">(optional)</span></label>
                            <input id="phone" name="phone" value="{{ old('phone') }}" class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="address">Address <span class="text-body-secondary fw-normal">(optional)</span></label>
                            <input id="address" name="address" value="{{ old('address') }}" class="form-control @error('address') is-invalid @enderror">
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                </div>
            </section>

            <section class="panel form-section">
                <div class="panel-head"><div><h2>Internet service</h2><p>The plan, billing day and router login.</p></div></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="plan_id">Plan</label>
                            <select id="plan_id" name="plan_id" class="form-select @error('plan_id') is-invalid @enderror" required>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}" @selected(old('plan_id') == $plan->id)>{{ $plan->name }} — ₱{{ number_format((float) $plan->price, 2) }} ({{ $plan->mikrotik_profile }})</option>
                                @endforeach
                            </select>
                            @error('plan_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-4"><label class="form-label" for="service_type">Service</label>
                            <select id="service_type" name="service_type" class="form-select">
                                <option value="pppoe" @selected(old('service_type') !== 'hotspot')>PPPoE</option>
                                <option value="hotspot" @selected(old('service_type') === 'hotspot')>Hotspot</option>
                            </select></div>
                        <div class="col-md-4"><label class="form-label" for="next_due_date">First due date</label>
                            <input id="next_due_date" name="next_due_date" type="date" value="{{ old('next_due_date', now()->addMonthNoOverflow()->toDateString()) }}" class="form-control @error('next_due_date') is-invalid @enderror" required>
                            <div class="form-text">Pick day 1–28 so it stays the same every month.</div>
                            @error('next_due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>

                        <div class="col-md-6"><label class="form-label" for="username">PPPoE / hotspot username</label>
                            <input id="username" name="username" value="{{ old('username') }}" class="form-control font-monospace @error('username') is-invalid @enderror" required>
                            @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="service_password">PPPoE / hotspot password</label>
                            <input id="service_password" name="service_password" type="text" class="form-control font-monospace @error('service_password') is-invalid @enderror" required autocomplete="off">
                            @error('service_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="panel" style="position: sticky; top: 1.5rem;">
                <div class="panel-head"><div><h2>Router</h2><p>MikroTik account for this client.</p></div><span class="icon-tile">@include('partials.icon', ['name' => 'router'])</span></div>
                <div class="panel-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="create_on_router" value="1" id="create_on_router" @checked(old('create_on_router', true))>
                        <label class="form-check-label" for="create_on_router">Also create this account on the MikroTik router <span class="d-block small text-body-secondary">Skipped if it already exists.</span></label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="send_welcome" @checked(old('send_welcome', true))>
                        <label class="form-check-label" for="send_welcome">Email them a welcome message <span class="d-block small text-body-secondary">Account number, plan, and how to set their portal password.</span></label>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="auto_suspend">Cut-off when unpaid</label>
                        <select id="auto_suspend" name="auto_suspend" class="form-select">
                            <option value="1" @selected(old('auto_suspend', '1') === '1')>Automatic: cut at midnight after the due date</option>
                            <option value="0" @selected(old('auto_suspend') === '0')>Manual: only when you click Suspend</option>
                        </select>
                        <div class="form-text">Either way, paying reconnects them automatically. You can change this later on the dashboard.</div>
                    </div>
                    <button class="btn btn-primary w-100 d-inline-flex justify-content-center align-items-center gap-2" @disabled($plans->isEmpty())>@include('partials.icon', ['name' => 'user-plus'])Add client</button>
                    <a href="{{ route('admin.dashboard') }}" class="btn btn-light-outline w-100 mt-2">Cancel</a>
                </div>
            </section>
        </div>
    </div>
</form>
@endsection
