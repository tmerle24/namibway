<x-mail::message>
# Your booking has been cancelled

Hi {{ $reservation->guest_name }},

Booking **{{ $reservation->reference }}** at {{ $reservation->listing?->name ?? 'the property' }},
arriving {{ $reservation->check_in->isoFormat('dddd D MMMM YYYY') }}, has been cancelled.

@if (filled($reservation->cancellation_reason))
Reason given: {{ $reservation->cancellation_reason }}
@endif

If this was not what you expected, reply to this email and the property will look into it.

{{ $reservation->listing?->name ?? config('app.name') }}
</x-mail::message>
