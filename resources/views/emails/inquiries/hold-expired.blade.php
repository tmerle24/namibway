<x-mail::message>
# Your booking request has expired

Hi {{ $inquiry->name }},

Sorry — your booking request at **{{ $inquiry->listing->name }}** wasn't confirmed by the property in time, so we've released the hold on it.

<x-mail::table>
| | |
|---|---|
| **Property** | {{ $inquiry->listing->name }} |
| **Check-in** | {{ $inquiry->check_in?->format('D, d M Y') ?? $inquiry->travel_dates ?? '—' }} |
| **Check-out** | {{ $inquiry->check_out?->format('D, d M Y') ?? '—' }} |
</x-mail::table>

You're welcome to submit a new request — availability may have changed since then.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
