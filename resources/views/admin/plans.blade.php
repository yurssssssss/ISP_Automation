@extends('layouts.admin')
@section('title', 'Plans')

@section('content')
<div class="page-head">
    <div>
        <h1>Plans</h1>
        <p>Active plans appear on the public site, cheapest first.</p>
    </div>
    <a href="{{ route('landing') }}#plans" target="_blank" rel="noopener" class="btn btn-light-outline d-inline-flex align-items-center gap-2">@include('partials.icon', ['name' => 'external'])See them on the site</a>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        @if ($plans->isEmpty())
            <div class="panel">
                <div class="empty">
                    <span class="icon-tile">@include('partials.icon', ['name' => 'layers'])</span>
                    <div class="fw-semibold text-body">No plans yet</div>
                    <div class="small">Add your first plan with the form.</div>
                </div>
            </div>
        @else
            <div class="row g-3">
                @foreach ($plans as $plan)
                    <div class="col-md-6">
                        <article class="admin-plan">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h2 class="h6 fw-bold mb-0">{{ $plan->name }}</h2>
                                <span @class(['status', 'status-active' => $plan->is_active, 'status-other' => ! $plan->is_active])>{{ $plan->is_active ? 'On the site' : 'Hidden' }}</span>
                            </div>
                            <div class="admin-plan-speed">{{ $plan->speed_mbps }}<small>Mbps</small></div>
                            <div class="fw-bold">₱{{ number_format((float) $plan->price, 2) }} <span class="fw-normal text-body-secondary small">/ month</span></div>
                            <dl>
                                <dt>MikroTik profile</dt><dd class="font-monospace">{{ $plan->mikrotik_profile }}</dd>
                                <dt>Subscribers</dt><dd>{{ $plan->subscriptions_count }}</dd>
                            </dl>
                            <form method="POST" action="{{ route('admin.plans.visibility', $plan) }}" class="mt-3"
                                  @if ($plan->is_active) data-confirm="Hide {{ $plan->name }} from the website and from new clients? Customers already on it keep it." @endif>
                                @csrf
                                <input type="hidden" name="is_active" value="{{ $plan->is_active ? 0 : 1 }}">
                                <button class="btn btn-sm btn-light-outline w-100">{{ $plan->is_active ? 'Hide plan' : 'Show on website' }}</button>
                            </form>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
    <div class="col-xl-4">
        <form method="POST" action="{{ route('admin.plans.store') }}" class="panel">
            @csrf
            <div class="panel-head"><div><h2>New plan</h2><p>It shows on the public site right away.</p></div></div>
            <div class="panel-body">
                @if ($errors->any())<div class="alert alert-danger small py-2 rounded-3">{{ $errors->first() }}</div>@endif
                <div class="mb-3"><label class="form-label" for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" class="form-control" placeholder="Fiber 20" required></div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label" for="speed_mbps">Speed (Mbps)</label><input id="speed_mbps" name="speed_mbps" type="number" min="1" value="{{ old('speed_mbps') }}" class="form-control" required></div>
                    <div class="col-6"><label class="form-label" for="price">Price (₱/month)</label><input id="price" name="price" type="number" step="0.01" min="1" value="{{ old('price') }}" class="form-control" required></div>
                </div>
                <div class="mb-3"><label class="form-label" for="mikrotik_profile">MikroTik PPP profile</label><input id="mikrotik_profile" name="mikrotik_profile" value="{{ old('mikrotik_profile') }}" class="form-control font-monospace" placeholder="20M" required>
                    <div class="form-text">Must match a profile in /ppp/profile on the router.</div></div>
                <button class="btn btn-primary w-100">Add plan</button>
            </div>
        </form>
    </div>
</div>
@endsection
