@extends('layouts.admin')
@section('title', 'Clients')

@section('content')
<div class="page-head">
    <div>
        <h1>Clients</h1>
        <p>Everyone on the system, cancelled customers included. Open one to edit, cancel or resume their service.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.clients.import') }}" class="btn btn-light-outline d-inline-flex align-items-center gap-2">@include('partials.icon', ['name' => 'file'])Import</a>
        <a href="{{ route('admin.clients.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">@include('partials.icon', ['name' => 'user-plus'])Add client</a>
    </div>
</div>

<section class="panel">
    <div class="panel-head">
        <div><h2>{{ $subscriptions->count() }} {{ Str::plural('client', $subscriptions->count()) }}</h2></div>
        <label class="search-box mb-0">
            @include('partials.icon', ['name' => 'search'])
            <input type="search" class="form-control" placeholder="Search name, account, username, email…" data-table-filter="clientsTable" aria-label="Search clients">
        </label>
    </div>
    <div class="table-responsive">
        <table class="table table-modern" id="clientsTable">
            <thead><tr><th>Client</th><th>Plan</th><th>Username</th><th>Next due</th><th>Service</th><th>Portal sign-in</th><th class="text-end"></th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $s)
                <tr>
                    <td>
                        <div class="cell-person">
                            <span class="avatar avatar-soft">{{ Str::upper(Str::substr($s->client->name, 0, 1)) }}</span>
                            <div><strong>{{ $s->client->name }}</strong><small>{{ $s->client->account_no }} · {{ $s->client->user?->email ?? $s->client->email }}</small></div>
                        </div>
                    </td>
                    <td>{{ $s->plan->name }}@if (! $s->plan->is_active)<span class="cell-sub">hidden plan</span>@endif</td>
                    <td class="font-monospace small">{{ $s->username }}</td>
                    <td class="text-nowrap">{{ $s->status === 'cancelled' ? '—' : $s->next_due_date->format('M j, Y') }}</td>
                    <td><span @class(['status', 'status-active' => $s->status === 'active', 'status-suspended' => $s->isSuspended(), 'status-other' => $s->status === 'cancelled'])>{{ Str::ucfirst($s->status) }}</span></td>
                    <td>
                        @if (! $s->client->user)
                            <span class="text-body-secondary small">no login</span>
                        @else
                            <span @class(['status', 'status-active' => $s->client->user->is_active, 'status-other' => ! $s->client->user->is_active])>{{ $s->client->user->is_active ? 'On' : 'Off' }}</span>
                        @endif
                    </td>
                    <td class="text-end"><a href="{{ route('admin.clients.edit', $s) }}" class="btn btn-sm btn-light-outline">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <div class="empty">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'users'])</span>
                        <div class="fw-semibold text-body">No clients yet</div>
                        <div class="small mb-3">Add them one by one or import a spreadsheet.</div>
                        <a href="{{ route('admin.clients.create') }}" class="btn btn-primary btn-sm">Add client</a>
                    </div>
                </td></tr>
            @endforelse
            <tr class="d-none" data-filter-empty><td colspan="7" class="empty">No clients match your search.</td></tr>
            </tbody>
        </table>
    </div>
</section>
@endsection
