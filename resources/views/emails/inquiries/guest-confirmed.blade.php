@php
    use App\Enums\InquiryKind;

    $isOrder = $inquiry->kind === InquiryKind::Order;
    $noun = match ($inquiry->kind) {
        InquiryKind::Order => 'order',
        InquiryKind::TableReservation => 'table',
        default => 'booking',
    };
@endphp
<x-mail::message>
# Your {{ $noun }} is confirmed!

Hi {{ $inquiry->name }},

Great news — your {{ $noun }} at **{{ $inquiry->sellerName() }}** has been confirmed.

<x-mail::table>
| | |
|---|---|
| **Property** | {{ $inquiry->sellerName() }} |
@if($inquiry->connector_reference)
| **Booking reference** | {{ $inquiry->connector_reference }} |
@endif
@if($inquiry->total_amount && ! $isOrder)
| **Total** | {{ number_format($inquiry->total_amount, 2) }} {{ $inquiry->currency ?? 'NAD' }} |
@endif
</x-mail::table>

<x-inquiry-details :inquiry="$inquiry" />

@if($partnerMessage)
**A message from {{ $inquiry->sellerName() }}:**

{{ $partnerMessage }}
@endif

@if($paymentUrl)
To secure it, {{ $inquiry->sellerName() }} asks for a deposit. The button below opens a
secure payment page — the amount due is shown there before anything is charged.

<x-mail::button :url="$paymentUrl" color="success">
Pay the deposit
</x-mail::button>

If the button does not work, copy this address into your browser:
{{ $paymentUrl }}
@else
The property will be in touch shortly with further details.
@endif

Have an amazing trip to Namibia!

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
