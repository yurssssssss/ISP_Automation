@extends('layouts.app')
@section('title', 'Add client')

@section('content')
<h1 class="h4 mb-3">Add client</h1>
@if ($plans->isEmpty())
    <div class="alert alert-warning">Add a plan first on the <a href="{{ route('admin.plans.index') }}">Plans</a> page.</div>
@endif
<form method="POST" action="{{ route('admin.clients.store') }}" class="card"><div class="card-body">
    @csrf
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="name">Full name</label>
            <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="email">Email (portal login)</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required
                   data-email-check="new" data-email-check-url="{{ route('email.check') }}">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-6"><label class="form-label" for="phone">Mobile</label>
            <input id="phone" name="phone" value="{{ old('phone') }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="address">Address</label>
            <input id="address" name="address" value="{{ old('address') }}" class="form-control"></div>

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

        <div class="col-12 form-check ms-1">
            <input class="form-check-input" type="checkbox" name="create_on_router" value="1" id="create_on_router" @checked(old('create_on_router', true))>
            <label class="form-check-label" for="create_on_router">Also create this account on the MikroTik router (skipped if it already exists)</label>
        </div>
    </div>
</div>
<div class="card-footer text-end"><button class="btn btn-primary" @disabled($plans->isEmpty())>Add client</button></div>
</form>
@endsection
