@extends('layouts.admin')
@section('title', $client->name)

@section('content')
@php($cancelled = $s->status === 'cancelled')
<div class="page-head">
    <div>
        <h1>{{ $client->name }}</h1>
        <p>{{ $client->account_no }} · PPPoE <span class="font-monospace">{{ $s->username }}</span> ·
            <span @class(['status', 'status-active' => $s->status === 'active', 'status-suspended' => $s->isSuspended(), 'status-other' => $cancelled])>{{ Str::ucfirst($s->status) }}</span>
            @if ($openBalance > 0)<span class="due-late ms-1">₱{{ number_format($openBalance, 2) }} unpaid</span>@endif
        </p>
    </div>
    <a href="{{ route('admin.clients.index') }}" class="btn btn-light-outline">Back to clients</a>
</div>

<form method="POST" action="{{ route('admin.clients.update', $s) }}">
    @csrf
    @method('PUT')
    <div class="row g-4">
        <div class="col-xl-8">
            <section class="panel form-section">
                <div class="panel-head"><div><h2>Client details</h2><p>Changing the email means they confirm the new one with a code at their next sign-in.</p></div></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="name">Full name</label>
                            <input id="name" name="name" value="{{ old('name', $client->name) }}" class="form-control @error('name') is-invalid @enderror" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="email">Email (portal login)</label>
                            <input id="email" name="email" type="email" value="{{ old('email', $client->user?->email ?? $client->email) }}" class="form-control @error('email') is-invalid @enderror" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="phone">Mobile</label>
                            <input id="phone" name="phone" value="{{ old('phone', $client->phone) }}" class="form-control @error('phone') is-invalid @enderror">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="address">Address</label>
                            <input id="address" name="address" value="{{ old('address', $client->address) }}" class="form-control @error('address') is-invalid @enderror">
                            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                </div>
            </section>

            <section class="panel form-section">
                <div class="panel-head"><div><h2>Internet service</h2><p>A new plan's speed applies on the router right away; its price from the next bill.</p></div></div>
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="plan_id">Plan</label>
                            <select id="plan_id" name="plan_id" class="form-select @error('plan_id') is-invalid @enderror" required>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}" @selected((int) old('plan_id', $s->plan_id) === $plan->id)>{{ $plan->name }} — ₱{{ number_format((float) $plan->price, 2) }} ({{ $plan->mikrotik_profile }}){{ $plan->is_active ? '' : ' · hidden' }}</option>
                                @endforeach
                            </select>
                            @error('plan_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="next_due_date">Next due date</label>
                            <input id="next_due_date" name="next_due_date" type="date" value="{{ old('next_due_date', $s->next_due_date->toDateString()) }}" class="form-control @error('next_due_date') is-invalid @enderror" required @disabled($cancelled)>
                            @if ($cancelled)<input type="hidden" name="next_due_date" value="{{ $s->next_due_date->toDateString() }}">@endif
                            <div class="form-text">An unpaid bill for the current cycle moves to the new date too.</div>
                            @error('next_due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label" for="auto_suspend">Cut-off when unpaid</label>
                            <select id="auto_suspend" name="auto_suspend" class="form-select">
                                <option value="1" @selected((string) old('auto_suspend', (int) $s->auto_suspend) === '1')>Automatic</option>
                                <option value="0" @selected((string) old('auto_suspend', (int) $s->auto_suspend) === '0')>Manual</option>
                            </select></div>
                        <div class="col-md-6"><label class="form-label" for="service_password">New PPPoE password <span class="text-body-secondary fw-normal">(leave empty to keep)</span></label>
                            <input id="service_password" name="service_password" type="text" autocomplete="off" class="form-control font-monospace @error('service_password') is-invalid @enderror">
                            <div class="form-text">Also changed on the router. Their own router then needs the new password.</div>
                            @error('service_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">PPPoE username</label>
                            <input class="form-control font-monospace" value="{{ $s->username }}" disabled>
                            <div class="form-text">Can't be changed here (it would rename the account on the router).</div></div>
                    </div>
                </div>
            </section>
            <div class="text-end mt-3"><button class="btn btn-primary">Save changes</button></div>
        </div>
    </div>
</form>

<div class="row g-4 mt-1">
    <div class="col-xl-8">
        <section class="panel">
            <div class="panel-head"><div><h2>Service and sign-in</h2><p>Stop or restart their internet service, and control whether they can sign in to the portal.</p></div></div>
            <div class="panel-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        @if ($cancelled)
                            <h3 class="h6 fw-bold">Resume service</h3>
                            <p class="small text-body-secondary">Reconnects them and starts billing again from the date you pick.</p>
                            <form method="POST" action="{{ route('admin.clients.resume', $s) }}" class="d-flex gap-2 align-items-start">
                                @csrf
                                <div class="flex-grow-1">
                                    <input type="date" name="next_due_date" value="{{ today()->addMonthNoOverflow()->toDateString() }}" min="{{ today()->toDateString() }}" class="form-control @error('next_due_date') is-invalid @enderror" aria-label="Next due date" required>
                                    @error('next_due_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <button class="btn btn-soft-success">Resume</button>
                            </form>
                        @else
                            <h3 class="h6 fw-bold">Cancel service</h3>
                            <p class="small text-body-secondary">Cuts them on the router and stops all future bills and reminders. Unpaid bills stay on their account.</p>
                            <form method="POST" action="{{ route('admin.clients.cancel', $s) }}" data-confirm="Cancel {{ $client->name }}'s internet service? They're cut on the router and get no more bills.">
                                @csrf
                                <button class="btn btn-soft-danger">Cancel service</button>
                            </form>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6 fw-bold">Portal sign-in</h3>
                        @if ($client->user)
                            <p class="small text-body-secondary">{{ $client->user->is_active ? 'They can sign in to see and pay their bills.' : 'Sign-in is turned off.' }} Keep it on while they still owe money, so they can pay.</p>
                            <form method="POST" action="{{ route('admin.clients.portal', $s) }}"
                                  @if ($client->user->is_active) data-confirm="Turn off portal sign-in for {{ $client->name }}? They're signed out on their next click." @endif>
                                @csrf
                                <input type="hidden" name="is_active" value="{{ $client->user->is_active ? 0 : 1 }}">
                                <button class="btn btn-light-outline">{{ $client->user->is_active ? 'Turn off sign-in' : 'Turn on sign-in' }}</button>
                            </form>
                        @else
                            <p class="small text-body-secondary">This client has no portal login.</p>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
@endsection
