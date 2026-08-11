<x-mail::message>
# A confirmed booking is not on the calendar

This request has been confirmed and the guest has been told they have a room,
but it could not be written onto the property's calendar. Nothing has been
cancelled — the booking stands, and the calendar is the part that is wrong.

> {{ $reason }}

<x-mail::table>
| | |
|---|---|
| **Guest** | {{ $inquiry->name }} |
| **Request** | #{{ $inquiry->id }} |
| **Property** | {{ $inquiry->listing?->name ?? '—' }} |
| **Arrival** | {{ $inquiry->check_in?->isoFormat('dddd D MMMM YYYY') ?? ($inquiry->travel_dates ?? '—') }} |
| **Departure** | {{ $inquiry->check_out?->isoFormat('dddd D MMMM YYYY') ?? '—' }} |
| **Guests** | {{ $inquiry->adults }} {{ Str::plural('adult', $inquiry->adults) }}@if ($inquiry->children), {{ $inquiry->children }} {{ Str::plural('child', $inquiry->children) }}@endif |
| **Email** | {{ $inquiry->email }} |
@if (filled($inquiry->phone))
| **Phone** | {{ $inquiry->phone }} |
@endif
</x-mail::table>

Enter the stay by hand on the property's calendar, or fix what stopped it and
confirm the request again — recording it a second time is not possible, so a
retry is safe.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
