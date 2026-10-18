<x-mail::message>
# Thank you, {{ $subscription->client->name }}!

We received your payment of **₱{{ number_format((float) $payment->amount, 2) }}** for invoice {{ $invoice->number }}.

@if ($reconnected)
**Your internet is back on.** It reconnects automatically — usually within a minute. If it doesn't, restart your router.
@endif

<x-mail::table>
| | |
|:--|--:|
| Paid on | {{ $payment->paid_at->format('M j, Y g:i A') }} |
| Method | {{ strtoupper($payment->method) }} |
| Reference | {{ $payment->reference ?? '—' }} |
| Remaining balance | ₱{{ number_format($invoice->balance(), 2) }} |
| Next due date | {{ $subscription->next_due_date->format('F j, Y') }} |
</x-mail::table>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
