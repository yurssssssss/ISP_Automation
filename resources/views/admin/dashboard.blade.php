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

@php($scheduler = $automation['scheduler'])
@php($queue = $automation['queue'])
@php($router = $automation['router'])
<section class="panel mb-4">
    <div class="panel-head">
        <div>
            <h2>Automatic billing</h2>
            <p>Bills, reminders, cut-offs and reconnections happen on their own.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span @class(['status', 'status-active' => $scheduler['running'], 'status-suspended' => ! $scheduler['running']])>Scheduler: {{ $scheduler['running'] ? 'running' : 'not running' }}</span>
            <span @class(['status', 'status-active' => ! $queue['stuck'], 'status-suspended' => $queue['stuck']])>
                Worker: {{ $queue['mode'] === 'sync' ? 'instant' : ($queue['stuck'] ? $queue['pending'].' waiting' : 'ok') }}
            </span>
            @if (! $router['configured'])
                <span class="status status-other">Router: not set up</span>
            @elseif ($router['lastCheck'])
                <span @class(['status', 'status-active' => $router['lastCheck']['ok'], 'status-suspended' => ! $router['lastCheck']['ok']])
                      title="{{ $router['lastCheck']['message'] }} ({{ $router['lastCheck']['at']->diffForHumans() }})">Router: {{ $router['lastCheck']['ok'] ? 'connected' : 'unreachable' }}</span>
            @else
                <span class="status status-other">Router: not tested</span>
            @endif
            <form method="POST" action="{{ route('admin.router.test') }}">
                @csrf
                <button class="btn btn-sm btn-light-outline">Test router</button>
            </form>
            <form method="POST" action="{{ route('admin.automation.run') }}" data-confirm="Run the billing checks now? This creates due bills, sends reminders and cuts accounts that are unpaid after their due date.">
                @csrf
                <button class="btn btn-sm btn-primary">Run checks now</button>
            </form>
        </div>
    </div>

    @if (! $router['configured'])
        <div class="alert alert-info rounded-0 border-0 small mb-0">
            <strong>No router connected yet.</strong> Billing still runs: cut-offs and reconnections are recorded here and applied to the MikroTik once it's connected.
            Fill in <code>MIKROTIK_HOST</code>, <code>MIKROTIK_USERNAME</code> and <code>MIKROTIK_PASSWORD</code>, restart, click <strong>Test router</strong>, then run <code>php artisan mikrotik:sync --now --create-missing</code> once.
        </div>
    @endif
    @if (! $scheduler['running'])
        <div class="alert alert-warning rounded-0 border-0 small mb-0">
            <strong>The scheduler isn't running</strong>{{ $scheduler['lastSeen'] ? ' (last seen '.$scheduler['lastSeen']->diffForHumans().')' : '' }}, so nothing below happens on its own.
            Start it with <code>php artisan schedule:work</code> (or <code>composer run dev</code>). On a server, add the cron entry from <code>routes/console.php</code>.
        </div>
    @endif
    @if ($queue['stuck'])
        <div class="alert alert-warning rounded-0 border-0 small mb-0">
            <strong>{{ $queue['pending'] }} router {{ Str::plural('change', $queue['pending']) }} and emails are waiting.</strong>
            Cut-offs and reconnections reach the MikroTik only while a worker runs: <code>php artisan queue:work</code>.
        </div>
    @endif
    @php($backup = $automation['backup'])
    @if ($backup && $backup['stale'])
        <div class="alert alert-warning rounded-0 border-0 small mb-0">
            <strong>{{ $backup['at'] ? 'No database backup since '.$backup['at']->format('M j, g:i A').'.' : 'No database backup yet.' }}</strong>
            Backups run every night; check the backup service with <code>.\docker\production.ps1 logs backup</code>, or make one now with <code>.\docker\production.ps1 backup</code>.
        </div>
    @endif
    @if ($queue['failed'] > 0)
        <div class="alert alert-danger rounded-0 border-0 small mb-0">
            {{ $queue['failed'] }} background {{ Str::plural('job', $queue['failed']) }} failed. See them with <code>php artisan queue:failed</code> and retry with <code>php artisan queue:retry all</code>.
        </div>
    @endif

    @if ($backup && ! $backup['stale'])
        <div class="panel-body py-2 small text-body-secondary border-top">
            Database backup: <strong class="text-body">{{ $backup['at']->format('M j, g:i A') }}</strong>
            ({{ $backup['bytes'] >= 1048576 ? number_format($backup['bytes'] / 1048576, 1).' MB' : number_format($backup['bytes'] / 1024, 0).' KB' }}) · {{ $backup['count'] }} {{ Str::plural('copy', $backup['count']) }} kept
        </div>
    @endif
    <div class="row g-0">
        @foreach ($automation['tasks'] as $task)
            <div class="col-md-6 col-xl-3">
                <div class="panel-body h-100 border-top {{ $loop->first ? '' : 'border-start-xl' }}">
                    <div class="small text-body-secondary fw-semibold">Daily at {{ \Carbon\Carbon::createFromFormat('H:i', $task['at'])->format('g:i A') }}</div>
                    <div class="fw-bold mb-1">{{ $task['label'] }}</div>
                    @if ($task['lastRun'])
                        <div class="small">{{ $task['summary'] }}</div>
                        <div class="small text-body-secondary">Last run {{ $task['lastRun']->diffForHumans() }}</div>
                    @else
                        <div class="small text-body-secondary">Hasn't run yet</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>

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
    @error('keep_on_until')
        <div class="alert alert-danger rounded-0 border-0 small mb-0">{{ $message }}</div>
    @enderror
    <div class="table-responsive">
        <table class="table table-modern" id="subscriptionsTable">
            <thead><tr><th>Client</th><th>Plan</th><th>Username</th><th>Next due</th><th>Status</th><th>Cut-off</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            @forelse ($subscriptions as $s)
                @php($daysToDue = (int) today()->diffInDays($s->next_due_date, false))
                <tr>
                    <td>
                        <div class="cell-person">
                            <span class="avatar avatar-soft">{{ Str::upper(Str::substr($s->client->name, 0, 1)) }}</span>
                            <div><a href="{{ route('admin.clients.edit', $s) }}" class="text-body text-decoration-none"><strong>{{ $s->client->name }}</strong></a><small>{{ $s->client->account_no }}</small></div>
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
                    <td>
                        <span @class(['status', 'status-active' => $s->status === 'active', 'status-suspended' => $s->isSuspended(), 'status-other' => ! in_array($s->status, ['active', 'suspended'], true)])>{{ Str::ucfirst($s->status) }}</span>
                        @if ($s->isOnHold())
                            <span class="cell-sub due-soon">Kept on until {{ $s->suspension_hold_until->format('M j') }}</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('admin.subscriptions.cutoff', $s) }}" class="d-flex align-items-center gap-2"
                              data-confirm="{{ $s->auto_suspend ? "Switch {$s->username} to manual cut-off? The system will never cut them automatically for an unpaid bill." : "Switch {$s->username} to automatic cut-off? They'll be cut at midnight whenever a bill is unpaid after its due date." }}">
                            @csrf
                            <input type="hidden" name="auto_suspend" value="{{ $s->auto_suspend ? 0 : 1 }}">
                            <span @class(['status', 'status-active' => $s->auto_suspend, 'status-other' => ! $s->auto_suspend])>{{ $s->auto_suspend ? 'Automatic' : 'Manual' }}</span>
                            <button class="btn btn-link btn-sm p-0" title="{{ $s->auto_suspend ? 'Stop cutting this customer automatically' : 'Cut this customer automatically when unpaid' }}">{{ $s->auto_suspend ? 'Set manual' : 'Set automatic' }}</button>
                        </form>
                    </td>
                    <td class="text-end">
                        @if ($s->isSuspended() && $s->auto_suspend && $s->has_overdue)
                            {{-- Still unpaid: without a date, tonight's run would cut them again. --}}
                            <form method="POST" action="{{ route('admin.subscriptions.restore', $s) }}" class="d-inline-flex align-items-center gap-1 justify-content-end">
                                @csrf
                                <label class="small text-body-secondary text-nowrap" for="keep-on-{{ $s->id }}">Keep on until</label>
                                <input id="keep-on-{{ $s->id }}" type="date" name="keep_on_until" value="{{ today()->addDays(3)->toDateString() }}" min="{{ today()->toDateString() }}" max="{{ today()->addDays(60)->toDateString() }}"
                                       class="form-control form-control-sm" style="width: 9.5rem;" required>
                                <button class="btn btn-sm btn-soft-success">Restore</button>
                            </form>
                        @elseif ($s->isSuspended())
                            <form method="POST" action="{{ route('admin.subscriptions.restore', $s) }}">@csrf<button class="btn btn-sm btn-soft-success">Restore</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.subscriptions.suspend', $s) }}" data-confirm="Suspend {{ $s->client->name }} ({{ $s->username }})? Their internet will be cut off.">@csrf<button class="btn btn-sm btn-soft-danger">Suspend</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">
                    <div class="empty">
                        <span class="icon-tile">@include('partials.icon', ['name' => 'users'])</span>
                        <div class="fw-semibold text-body">No subscriptions yet</div>
                        <div class="small mb-3">Add your first client to start billing.</div>
                        <a href="{{ route('admin.clients.create') }}" class="btn btn-primary btn-sm">Add client</a>
                    </div>
                </td></tr>
            @endforelse
            <tr class="d-none" data-filter-empty><td colspan="7" class="empty">No subscriptions match your search.</td></tr>
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
