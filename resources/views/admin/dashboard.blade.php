@extends('layouts.admin')
@section('title', 'Dashboard')

@section('content')
@php($hour = now()->hour)
<div class="page-head">
    <div>
        <h1>{{ $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening') }}, {{ auth()->user()->name }}</h1>
        <p>{{ now()->format('l, F j, Y') }} · Here's how your network is doing.</p>
    </div>
    <a href="{{ route('admin.clients.create') }}" class="btn btn-primary d-inline-flex align-items-center gap-2">@include('partials.icon', ['name' => 'user-plus'])Add client</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl">
        <div class="stat">
            <div class="stat-top"><span class="stat-label">Clients</span><span class="icon-tile">@include('partials.icon', ['name' => 'users'])</span></div>
            <div class="stat-value">{{ number_format($stats['clients']) }}</div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="stat">
            <div class="stat-top"><span class="stat-label">Active</span><span class="icon-tile tone-ok">@include('partials.icon', ['name' => 'wifi'])</span></div>
            <div class="stat-value">{{ number_format($stats['active']) }}</div>
            <div class="stat-note">Connected now</div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="stat">
            <div class="stat-top"><span class="stat-label">Suspended</span><span class="icon-tile tone-bad">@include('partials.icon', ['name' => 'pause'])</span></div>
            <div class="stat-value">{{ number_format($stats['suspended']) }}</div>
            <div class="stat-note">Cut for non-payment</div>
        </div>
    </div>
    <div class="col-6 col-xl">
        <div class="stat">
            <div class="stat-top"><span class="stat-label">Receivable</span><span class="icon-tile tone-warn">@include('partials.icon', ['name' => 'receipt'])</span></div>
            <div class="stat-value">₱{{ number_format($stats['receivable'], 2) }}</div>
            <div class="stat-note">{{ $stats['overdue'] }} overdue {{ Str::plural('bill', $stats['overdue']) }}</div>
        </div>
    </div>
    <div class="col-12 col-xl">
        <div class="stat stat-feature">
            <div class="stat-top"><span class="stat-label">Collected this month</span><span class="icon-tile">@include('partials.icon', ['name' => 'wallet'])</span></div>
            <div class="stat-value">₱{{ number_format($stats['collected'], 2) }}</div>
            <div class="stat-note">Since {{ now()->startOfMonth()->format('M j') }}</div>
        </div>
    </div>
</div>

<section class="panel mb-4">
    <div class="panel-head">
        <div>
            <h2>Subscriptions</h2>
            <p>Suspended accounts first, then by next due date.</p>
        </div>
        <label class="search-box mb-0">
            @include('partials.icon', ['name' => 'search'])
            <input type="search" class="form-control" placeholder="Search name, account, username…" data-table-filter="subscriptionsTable" aria-label="Search subscriptions">
        </label>
    </div>
    <div class="table-responsive">
        <table class="table table-modern" id="subscriptionsTable">
            <thead><tr><th>Client</th><th>Plan</th><th>Username</th><th>Next due</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $s)
                @php($daysToDue = (int) today()->diffInDays($s->next_due_date, false))
                <tr>
                    <td>
                        <div class="cell-person">
                            <span class="avatar avatar-soft">{{ Str::upper(Str::substr($s->client->name, 0, 1)) }}</span>
                            <div><strong>{{ $s->client->name }}</strong><small>{{ $s->client->account_no }}</small></div>
                        </div>
                    </td>
                    <td>{{ $s->plan->name }}<span class="cell-sub">{{ $s->plan->speed_mbps }} Mbps · ₱{{ number_format((float) $s->plan->price, 2) }}</span></td>
                    <td><span class="font-monospace small">{{ $s->username }}</span><span class="cell-sub text-uppercase">{{ $s->service_type }}</span></td>
                    <td class="text-nowrap">
                        {{ $s->next_due_date->format('M j, Y') }}
                        @if ($daysToDue < 0)
                            <span class="cell-sub due-late">{{ abs($daysToDue) }} {{ Str::plural('day', abs($daysToDue)) }} overdue</span>
                        @elseif ($daysToDue === 0)
                            <span class="cell-sub due-late">Due today</span>
                        @elseif ($daysToDue <= 3)
                            <span class="cell-sub due-soon">Due in {{ $daysToDue }} {{ Str::plural('day', $daysToDue) }}</span>
                        @endif
                    </td>
                    <td><span @class(['status', 'status-active' => $s->status === 'active', 'status-suspended' => $s->isSuspended(), 'status-other' => ! in_array($s->status, ['active', 'suspended'], true)])>{{ Str::ucfirst($s->status) }}</span></td>
                    <td class="text-end">
                        @if ($s->isSuspended())
                            <form method="POST" action="{{ route('admin.subscriptions.restore', $s) }}">@csrf<button class="btn btn-sm btn-soft-success">Restore</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.subscriptions.suspend', $s) }}" data-confirm="Suspend {{ $s->client->name }} ({{ $s->username }})? Their internet will be cut off.">@csrf<button class="btn btn-sm btn-soft-danger">Suspend</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">
                    <div class="empty">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'users'])</span>
                        <div class="fw-semibold text-body">No subscriptions yet</div>
                        <div class="small mb-3">Add your first client to start billing.</div>
                        <a href="{{ route('admin.clients.create') }}" class="btn btn-primary btn-sm">Add client</a>
                    </div>
                </td></tr>
            @endforelse
            <tr class="d-none" data-filter-empty><td colspan="6" class="empty">No subscriptions match your search.</td></tr>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-4">
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-head">
                <div>
                    <h2>Unpaid bills</h2>
                    <p>Record cash paid at the office. Overdue bills are marked red.</p>
                </div>
            </div>
            @if ($errors->has('amount') || $errors->has('reference'))
                <div class="alert alert-danger rounded-0 border-0 small mb-0">{{ $errors->first('amount') ?: $errors->first('reference') }}</div>
            @endif
            <div class="table-responsive">
                <table class="table table-modern">
                    <thead><tr><th>Bill</th><th>Due</th><th class="text-end">Balance</th><th>Record cash</th></tr></thead>
                    <tbody>
                    @forelse ($openInvoices as $invoice)
                        <tr>
                            <td><strong class="d-block">{{ $invoice->subscription->client->name }}</strong><span class="cell-sub">{{ $invoice->number }}</span></td>
                            <td class="text-nowrap">
                                {{ $invoice->due_date->format('M j') }}
                                @if ($invoice->due_date->isBefore(today()))<span class="cell-sub due-late">Overdue</span>@endif
                            </td>
                            <td class="text-end fw-semibold text-nowrap">₱{{ number_format($invoice->balance(), 2) }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.invoices.cash', $invoice) }}" class="cash-form d-flex gap-1">
                                    @csrf
                                    <input name="amount" type="number" step="0.01" min="1" max="{{ $invoice->balance() }}" value="{{ $invoice->balance() }}" class="form-control" style="max-width: 7rem;" aria-label="Amount for {{ $invoice->number }}">
                                    <input name="reference" type="text" class="form-control" placeholder="OR #" style="max-width: 6rem;" aria-label="Receipt number for {{ $invoice->number }}">
                                    <button class="btn btn-outline-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">
                            <div class="empty">
                                <span class="icon-tile tone-ok">@include('partials.icon', ['name' => 'check'])</span>
                                <div class="fw-semibold text-body">All caught up</div>
                                <div class="small">No unpaid bills right now.</div>
                            </div>
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-head">
                <div>
                    <h2>Router activity</h2>
                    <p>Latest changes sent to the MikroTik.</p>
                </div>
                <span class="icon-tile">@include('partials.icon', ['name' => 'router'])</span>
            </div>
            @if ($routerLogs->isEmpty())
                <div class="empty">
                    <span class="icon-tile">@include('partials.icon', ['name' => 'activity'])</span>
                    <div class="small">No router changes yet.</div>
                </div>
            @else
                <ul class="activity">
                    @foreach ($routerLogs as $log)
                        <li @class(['is-fail' => ! $log->success])>
                            <div><strong>{{ $log->action }}</strong> <span class="font-monospace">{{ $log->username }}</span></div>
                            @if ($log->message)<div class="text-body-secondary small">{{ $log->message }}</div>@endif
                            <div class="activity-meta">{{ $log->success ? 'Done' : 'Failed' }} · {{ $log->created_at->diffForHumans() }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
@endsection
