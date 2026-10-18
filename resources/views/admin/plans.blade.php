@extends('layouts.app')
@section('title', 'Plans')

@section('content')
<h1 class="h4 mb-3">Plans</h1>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Name</th><th>Speed</th><th class="text-end">Monthly</th><th>MikroTik profile</th><th class="text-end">Subscribers</th></tr></thead>
                <tbody>
                @forelse ($plans as $plan)
                    <tr>
                        <td>{{ $plan->name }}</td>
                        <td>{{ $plan->speed_mbps }} Mbps</td>
                        <td class="text-end">₱{{ number_format((float) $plan->price, 2) }}</td>
                        <td class="font-monospace small">{{ $plan->mikrotik_profile }}</td>
                        <td class="text-end">{{ $plan->subscriptions_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-body-secondary py-4">No plans yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-lg-4">
        <form method="POST" action="{{ route('admin.plans.store') }}" class="card"><div class="card-body">
            @csrf
            <h2 class="h6">New plan</h2>
            <div class="mb-2"><label class="form-label" for="name">Name</label><input id="name" name="name" value="{{ old('name') }}" class="form-control" placeholder="Fiber 20" required></div>
            <div class="mb-2"><label class="form-label" for="speed_mbps">Speed (Mbps)</label><input id="speed_mbps" name="speed_mbps" type="number" min="1" value="{{ old('speed_mbps') }}" class="form-control" required></div>
            <div class="mb-2"><label class="form-label" for="price">Monthly price (₱)</label><input id="price" name="price" type="number" step="0.01" min="1" value="{{ old('price') }}" class="form-control" required></div>
            <div class="mb-3"><label class="form-label" for="mikrotik_profile">MikroTik PPP profile</label><input id="mikrotik_profile" name="mikrotik_profile" value="{{ old('mikrotik_profile') }}" class="form-control font-monospace" placeholder="20M" required>
                <div class="form-text">Must match a profile in /ppp/profile on the router.</div></div>
            @if ($errors->any())<div class="alert alert-danger small py-2">{{ $errors->first() }}</div>@endif
            <button class="btn btn-primary w-100">Add plan</button>
        </div></form>
    </div>
</div>
@endsection
