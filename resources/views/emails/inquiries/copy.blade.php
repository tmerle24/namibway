<x-mail::message>
# Thanks, {{ $inquiry->name }}

We have passed your enquiry to **{{ $inquiry->listing->name }}**. They answer these
themselves, so the reply comes from them — usually within a day or two.

This is your copy of what you sent:

<x-mail::table>
| | |
|---|---|
| **Name** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?: '—' }} |
| **Dates** | {{ $inquiry->travel_dates ?: $inquiry->check_in?->format('D, d M Y') ?: '—' }} |
| **Guests** | {{ $inquiry->adults }} adult{{ $inquiry->adults !== 1 ? 's' : '' }}@if ($inquiry->children > 0), {{ $inquiry->children }} child{{ $inquiry->children !== 1 ? 'ren' : '' }}@endif |
</x-mail::table>

@if ($inquiry->message)
**Your message**

{{ $inquiry->message }}
@endif

Nothing is booked and nothing is owed. If you do not hear back, reply to this
email and we will follow it up for you.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
