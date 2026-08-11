<x-mail::message>
# Your booking is confirmed

Hi {{ $reservation->guest_name }},

{{ $reservation->listing?->name ?? 'The property' }} has your booking. Please keep this reference —
it is what to quote when you arrive or if you need to change anything.

<x-mail::table>
| | |
|---|---|
| **Reference** | {{ $reservation->reference }} |
| **Property** | {{ $reservation->listing?->name ?? '—' }} |
| **Arrival** | {{ $reservation->check_in->isoFormat('dddd D MMMM YYYY') }} |
| **Departure** | {{ $reservation->check_out->isoFormat('dddd D MMMM YYYY') }} |
| **Nights** | {{ $reservation->nights() }} |
| **Guests** | {{ $reservation->adults }} {{ Str::plural('adult', $reservation->adults) }}@if ($reservation->children), {{ $reservation->children }} {{ Str::plural('child', $reservation->children) }}@endif |
</x-mail::table>

## Rooms

<x-mail::table>
| Room | Included | Dates |
|---|---|---|
@foreach ($reservation->units as $unit)
| {{ $unit->roomType?->name ?? 'Room' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif | {{ $unit->soldAs() ?? '—' }} | {{ $unit->check_in->isoFormat('D MMM') }} – {{ $unit->check_out->isoFormat('D MMM') }} |
@endforeach
</x-mail::table>

@if ($reservation->total_amount !== null)
**Total: {{ \App\Support\Money::format($reservation->total_amount, $reservation->currency) }}**

Payment is arranged with the property directly.
@endif

We look forward to seeing you.

{{ $reservation->listing?->name ?? config('app.name') }}
</x-mail::message>
