<x-mail::message>
# New booking

@if ($notice)
> {{ $notice }}
@endif

<x-mail::table>
| | |
|---|---|
| **Guest** | {{ $reservation->guest_name }} |
| **Reference** | {{ $reservation->reference }} |
| **Property** | {{ $reservation->listing?->name ?? '—' }} |
| **Arrival** | {{ $reservation->check_in->isoFormat('dddd D MMMM YYYY') }} |
| **Departure** | {{ $reservation->check_out->isoFormat('dddd D MMMM YYYY') }} |
| **Nights** | {{ $reservation->nights() }} |
| **Guests** | {{ $reservation->adults }} {{ Str::plural('adult', $reservation->adults) }}@if ($reservation->children), {{ $reservation->children }} {{ Str::plural('child', $reservation->children) }}@endif |
| **Taken by** | {{ $reservation->source->label() }} |
@if (filled($reservation->guest_email))
| **Email** | {{ $reservation->guest_email }} |
@endif
@if (filled($reservation->guest_phone))
| **Phone** | {{ $reservation->guest_phone }} |
@endif
</x-mail::table>

## Rooms

<x-mail::table>
| Room | Sold as | Dates | Amount |
|---|---|---|---|
@foreach ($reservation->units as $unit)
| {{ $unit->bookableUnit?->name ?? 'Room' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif | {{ $unit->soldAs() ?? '—' }} | {{ $unit->check_in->isoFormat('D MMM') }} – {{ $unit->check_out->isoFormat('D MMM') }} | {{ \App\Support\Money::format($unit->total_amount, $unit->currency) }} |
@endforeach
</x-mail::table>

@if ($reservation->total_amount !== null)
@if ($reservation->charges->isNotEmpty())
{{-- Named rather than folded into one number: a guest asked to pay a park
     permit on arrival should have read about it here first. --}}
Stay: {{ \App\Support\Money::format($reservation->stayAmount(), $reservation->currency) }}
@foreach ($reservation->charges as $charge)
{{ $charge->label() }}: {{ \App\Support\Money::format($charge->amount, $charge->currency) }}@if ($charge->is_included) (included)@endif

@endforeach
@endif
**Total: {{ \App\Support\Money::format($reservation->total_amount, $reservation->currency) }}**
@endif

@if (filled($reservation->notes))
## Notes

{{ $reservation->notes }}
@endif
</x-mail::message>
