<x-mail::message>
# Hi {{ $subscription->client->name }},

Your new internet bill for **{{ $subscription->plan->name }}** is ready.

<x-mail::table>
| | |
|:--|--:|
| Invoice | {{ $invoice->number }} |
| Period | {{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }} |
| Amount due | ₱{{ number_format($invoice->balance(), 2) }} |
| Due date | {{ $invoice->due_date->format('F j, Y') }} |
</x-mail::table>

<x-mail::button :url="route('portal.dashboard')">
View and pay
</x-mail::button>

Please pay on or before **{{ $invoice->due_date->format('F j') }}**. If it's still unpaid, service pauses on {{ $pausesOn->format('F j') }} and turns back on automatically once you pay.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
