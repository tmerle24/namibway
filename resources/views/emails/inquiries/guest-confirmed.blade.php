<x-mail::message>
# Your booking is confirmed!

Hi {{ $inquiry->name }},

Great news — your booking at **{{ $inquiry->listing->name }}** has been confirmed.

<x-mail::table>
| | |
|---|---|
| **Property** | {{ $inquiry->listing->name }} |
| **Check-in** | {{ $inquiry->check_in?->format('D, d M Y') ?? $inquiry->travel_dates ?? '—' }} |
| **Check-out** | {{ $inquiry->check_out?->format('D, d M Y') ?? '—' }} |
| **Guests** | {{ $inquiry->adults }} adult{{ $inquiry->adults !== 1 ? 's' : '' }}@if($inquiry->children > 0), {{ $inquiry->children }} child{{ $inquiry->children !== 1 ? 'ren' : '' }}@endif |
@if($inquiry->connector_reference)
| **Booking reference** | {{ $inquiry->connector_reference }} |
@endif
@if($inquiry->total_amount)
| **Total** | {{ number_format($inquiry->total_amount, 2) }} {{ $inquiry->currency ?? 'NAD' }} |
@endif
</x-mail::table>

The property will be in touch shortly with further details.

Have an amazing trip to Namibia!

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
