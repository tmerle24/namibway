@php
    /** @var \App\Models\PaymentIntent $intent */
    $reservation = $intent->reservation;
    $property = $reservation?->listing?->name ?? config('app.name');
@endphp

<x-mail::message>
# {{ $intent->purpose->guestLabel() }} for your booking

Hi {{ $reservation?->guest_name ?? 'there' }},

{{ $property }} has asked for the {{ strtolower($intent->purpose->label()) }} on your booking.

<x-mail::table>
| | |
|---|---|
| **Property** | {{ $property }} |
@if($reservation)
| **Check-in** | {{ $reservation->check_in->format('D, d M Y') }} |
| **Check-out** | {{ $reservation->check_out->format('D, d M Y') }} |
| **Reference** | {{ $reservation->reference }} |
@endif
| **Amount due** | {{ number_format($intent->amount, 2) }} {{ $intent->currency }} |
</x-mail::table>

@if($partnerMessage)
**A message from {{ $property }}:**

{{ $partnerMessage }}
@endif

<x-mail::button :url="$paymentUrl" color="success">
Pay {{ number_format($intent->amount, 2) }} {{ $intent->currency }}
</x-mail::button>

If the button does not work, copy this address into your browser:
{{ $paymentUrl }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
