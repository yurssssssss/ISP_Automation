<x-mail::message>
# Hi {{ $subscription->client->name }},

Your internet service (account **{{ $subscription->client->account_no }}**) has been paused because
@if ($invoice)
invoice **{{ $invoice->number }}** (₱{{ number_format($invoice->balance(), 2) }}) was not paid by {{ $invoice->due_date->format('F j, Y') }}.
@else
of an unpaid bill.
@endif

As soon as you pay, your connection turns back on automatically — usually within a minute.

<x-mail::button :url="route('portal.dashboard')">
Pay and reconnect
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
