@extends('layouts.admin')
@section('title', 'Import clients')

@section('content')
<div class="page-head">
    <div>
        <h1>Import clients</h1>
        <p>Add many customers at once from a spreadsheet. Every row is checked first; if any row has a problem, nothing is imported.</p>
    </div>
    <a href="{{ route('admin.clients.import.template') }}" class="btn btn-light-outline d-inline-flex align-items-center gap-2">@include('partials.icon', ['name' => 'file'])Download template</a>
</div>

@if (session('importErrors'))
    <section class="panel mb-4 border-danger-subtle">
        <div class="panel-head">
            <div>
                <h2>Nothing was imported: {{ session('importErrorCount') }} {{ Str::plural('row', session('importErrorCount')) }} need fixing</h2>
                <p>Fix these lines in the spreadsheet, save it as CSV again, and re-upload the whole file.</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-modern">
                <thead><tr><th style="width: 6rem;">Line</th><th>Problem</th></tr></thead>
                <tbody>
                @foreach (session('importErrors') as $error)
                    <tr><td class="fw-semibold">{{ $error['line'] }}</td><td>{{ $error['message'] }}</td></tr>
                @endforeach
                @if (session('importErrorCount') > count(session('importErrors')))
                    <tr><td colspan="2" class="text-body-secondary">…and {{ session('importErrorCount') - count(session('importErrors')) }} more.</td></tr>
                @endif
                </tbody>
            </table>
        </div>
    </section>
@endif

<div class="row g-4">
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-head"><div><h2>Columns</h2><p>One customer per row, with these column names in the first row (the template has them).</p></div></div>
            <div class="table-responsive">
                <table class="table table-modern">
                    <thead><tr><th>Column</th><th>Required</th><th>What to put</th></tr></thead>
                    <tbody>
                        <tr><td class="font-monospace">name</td><td>yes</td><td>Full name</td></tr>
                        <tr><td class="font-monospace">email</td><td>yes</td><td>Their portal login; must be unique</td></tr>
                        <tr><td class="font-monospace">phone</td><td>no</td><td>Mobile number</td></tr>
                        <tr><td class="font-monospace">address</td><td>no</td><td>Installation address</td></tr>
                        <tr><td class="font-monospace">plan</td><td>yes</td><td>A plan name or its MikroTik profile:
                            @forelse ($plans as $plan)<code>{{ $plan->name }}</code>@if (! $loop->last), @endif @empty <em>add plans first</em>@endforelse</td></tr>
                        <tr><td class="font-monospace">service</td><td>no</td><td><code>pppoe</code> (default) or <code>hotspot</code></td></tr>
                        <tr><td class="font-monospace">username</td><td>yes</td><td>The PPPoE secret name, exactly as on the router</td></tr>
                        <tr><td class="font-monospace">password</td><td>yes</td><td>The PPPoE password (6+ characters). Used only if the system has to create the account on the router.</td></tr>
                        <tr><td class="font-monospace">next_due_date</td><td>yes</td><td>Their next due date, today or later: <code>2026-11-15</code> (or 11/15/2026)</td></tr>
                        <tr><td class="font-monospace">cutoff</td><td>no</td><td><code>automatic</code> (default) or <code>manual</code></td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <form method="POST" action="{{ route('admin.clients.import.store') }}" enctype="multipart/form-data" class="panel">
            @csrf
            <div class="panel-head"><div><h2>Upload</h2><p>Up to {{ number_format($maxRows) }} customers per file.</p></div><span class="icon-tile">@include('partials.icon', ['name' => 'users'])</span></div>
            <div class="panel-body">
                <div class="mb-3">
                    <label class="form-label" for="file">CSV file</label>
                    <input id="file" name="file" type="file" accept=".csv,text/csv" class="form-control @error('file') is-invalid @enderror" required>
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">In Excel or Google Sheets: File → Save as / Download → <strong>CSV</strong>.</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="send_welcome">
                    <label class="form-check-label" for="send_welcome">Email each customer a welcome message
                        <span class="d-block small text-body-secondary">Brevo's free plan sends 300 emails a day: for bigger lists, import in parts on different days.</span></label>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="create_missing" value="1" id="create_missing">
                    <label class="form-check-label" for="create_missing">Create PPPoE accounts the router doesn't have yet
                        <span class="d-block small text-body-secondary">Leave off when the customers already exist on the MikroTik (the usual case when moving an existing business).</span></label>
                </div>
                <button class="btn btn-primary w-100" @disabled($plans->isEmpty())>Check and import</button>
            </div>
        </form>
    </div>
</div>
@endsection
