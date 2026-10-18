@extends('layouts.app')
@section('title', 'My account')

@section('content')
<h1 class="h4 mb-1">Hi, {{ $client->name }}</h1>
<p class="text-body-secondary">Account {{ $client->account_no }}</p>

@foreach ($subscriptions as $s)
    <div class="card mb-3 border-{{ $s->isSuspended() ? 'danger' : 'success' }}">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <div><div class="small text-body-secondary">Plan</div><div class="fw-semibold">{{ $s->plan->name }} · {{ $s->plan->speed_mbps }} Mbps</div></div>
            <div><div class="small text-body-secondary">Monthly</div><div class="fw-semibold">₱{{ number_format((float) $s->plan->price, 2) }}</div></div>
            <div><div class="small text-body-secondary">Next due</div><div class="fw-semibold">{{ $s->next_due_date->format('F j, Y') }}</div></div>
            <div><div class="small text-body-secondary">Status</div>
                <span class="badge fs-6 text-bg-{{ $s->isSuspended() ? 'danger' : 'success' }}">{{ $s->isSuspended() ? 'Suspended — pay to reconnect' : 'Connected' }}</span></div>
        </div>
    </div>
@endforeach

<div class="card">
    <div class="card-header fw-semibold">Bills</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Invoice</th><th>Period</th><th>Due</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($invoices as $invoice)
                <tr>
                    <td class="small">{{ $invoice->number }}</td>
                    <td class="small">{{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}</td>
                    <td>{{ $invoice->due_date->format('M j, Y') }}</td>
                    <td class="text-end">₱{{ number_format((float) $invoice->amount, 2) }}
                        @if ($invoice->status === 'partial')<div class="small text-body-secondary">₱{{ number_format($invoice->balance(), 2) }} left</div>@endif</td>
                    <td><span class="badge text-bg-{{ $invoice->status === 'paid' ? 'success' : ($invoice->due_date->isPast() ? 'danger' : 'warning') }}">{{ $invoice->status }}</span></td>
                    <td class="text-end">
                        @if ($invoice->isOpen() && $payOnline)
                            <form method="POST" action="{{ route('portal.invoices.pay', $invoice) }}">@csrf
                                <button class="btn btn-sm btn-primary">Pay ₱{{ number_format($invoice->balance(), 2) }}</button></form>
                        @elseif ($invoice->paid_at)
                            <span class="small text-body-secondary">Paid {{ $invoice->paid_at->format('M j') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-body-secondary py-4">No bills yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
