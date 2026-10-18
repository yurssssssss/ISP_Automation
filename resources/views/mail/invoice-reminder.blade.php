<x-mail::message>
# Hi {{ $subscription->client->name }},

Your internet bill for **{{ $subscription->plan->name }}** is due on **{{ $invoice->due_date->format('F j, Y') }}**.

<x-mail::table>
| | |
|:--|--:|
| Invoice | {{ $invoice->number }} |
| Period | {{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }} |
| Amount due | ₱{{ number_format($invoice->balance(), 2) }} |
</x-mail::table>

<x-mail::button :url="route('portal.dashboard')">
Pay now
</x-mail::button>

To avoid interruption, please pay on or before the due date{{ $graceDays ? ' (service is paused '.$graceDays.' day'.($graceDays > 1 ? 's' : '').' after it)' : '' }}.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
