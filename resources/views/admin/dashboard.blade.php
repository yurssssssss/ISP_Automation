@extends('layouts.app')
@section('title', 'Admin')

@section('content')
<div class="row g-3 mb-4">
    @foreach ([
        ['Clients', number_format($stats['clients'])],
        ['Active', number_format($stats['active'])],
        ['Suspended', number_format($stats['suspended'])],
        ['Receivable', '₱'.number_format($stats['receivable'], 2)],
        ['Collected this month', '₱'.number_format($stats['collected'], 2)],
    ] as [$label, $value])
        <div class="col-6 col-md"><div class="card h-100"><div class="card-body">
            <div class="text-body-secondary small">{{ $label }}</div>
            <div class="fs-4 fw-semibold">{{ $value }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Subscriptions</span>
        <a href="{{ route('admin.clients.create') }}" class="btn btn-sm btn-primary">Add client</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Account</th><th>Client</th><th>Plan</th><th>Username</th><th>Next due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $s)
                <tr>
                    <td>{{ $s->client->account_no }}</td>
                    <td>{{ $s->client->name }}</td>
                    <td>{{ $s->plan->name }}</td>
                    <td class="font-monospace small">{{ $s->username }} <span class="text-body-secondary">({{ $s->service_type }})</span></td>
                    <td>{{ $s->next_due_date->format('M j, Y') }}</td>
                    <td><span class="badge text-bg-{{ $s->isSuspended() ? 'danger' : 'success' }}">{{ $s->status }}</span></td>
                    <td class="text-end">
                        @if ($s->isSuspended())
                            <form method="POST" action="{{ route('admin.subscriptions.restore', $s) }}">@csrf<button class="btn btn-sm btn-outline-success">Restore</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.subscriptions.suspend', $s) }}">@csrf<button class="btn btn-sm btn-outline-danger">Suspend</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-body-secondary py-4">No subscriptions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">Unpaid invoices</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Invoice</th><th>Client</th><th>Due</th><th class="text-end">Balance</th><th>Record cash</th></tr></thead>
                    <tbody>
                    @forelse ($openInvoices as $invoice)
                        <tr @class(['table-danger' => $invoice->due_date->isPast()])>
                            <td class="small">{{ $invoice->number }}</td>
                            <td>{{ $invoice->subscription->client->name }}</td>
                            <td>{{ $invoice->due_date->format('M j') }}</td>
                            <td class="text-end">₱{{ number_format($invoice->balance(), 2) }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.invoices.cash', $invoice) }}" class="d-flex gap-1">
                                    @csrf
                                    <input name="amount" type="number" step="0.01" min="1" max="{{ $invoice->balance() }}" value="{{ $invoice->balance() }}" class="form-control form-control-sm" style="max-width:7rem" aria-label="Amount">
                                    <input name="reference" type="text" class="form-control form-control-sm" placeholder="OR #" style="max-width:6rem" aria-label="Receipt number">
                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-4">Nothing unpaid.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @error('amount')<div class="text-danger small p-2">{{ $message }}</div>@enderror
            @error('reference')<div class="text-danger small p-2">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Router activity</div>
            <ul class="list-group list-group-flush small">
                @forelse ($routerLogs as $log)
                    <li class="list-group-item">
                        <span class="badge text-bg-{{ $log->success ? 'success' : 'danger' }}">{{ $log->action }}</span>
                        <span class="font-monospace">{{ $log->username }}</span>
                        <span class="text-body-secondary">· {{ $log->created_at->diffForHumans() }}</span>
                        <div class="text-body-secondary">{{ $log->message }}</div>
                    </li>
                @empty
                    <li class="list-group-item text-body-secondary">No router changes yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
