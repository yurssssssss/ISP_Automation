<x-mail::message>
# Welcome, {{ $client->name }}!

Your internet account with {{ config('app.name') }} is ready.

<x-mail::table>
| | |
|:--|--:|
| Account | {{ $client->account_no }} |
| Plan | {{ $plan->name }} · {{ $plan->speed_mbps }} Mbps |
| Monthly | ₱{{ number_format((float) $plan->price, 2) }} |
| PPPoE username | {{ $subscription->username }} |
| First due date | {{ $subscription->next_due_date->format('F j, Y') }} |
</x-mail::table>

To see your bills and pay online, choose a password for your account first. We'll email you a 6-digit code to confirm it's you.

<x-mail::button :url="$setPasswordUrl">
Set your password
</x-mail::button>

Your bill is emailed a few days before each due date, with a reminder before it's due.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
