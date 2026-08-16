@php
    use App\Enums\InquiryKind;

    $isOrder = $inquiry->kind === InquiryKind::Order;
    $noun = match ($inquiry->kind) {
        InquiryKind::Order => 'order',
        InquiryKind::TableReservation => 'table booking',
        default => 'booking request',
    };
@endphp
<x-mail::message>
# New {{ $noun }} — action required

You have received a {{ $noun }} via NamibWay that requires your confirmation.

<x-mail::table>
| | |
|---|---|
| **Guest** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?? '—' }} |
| **Property** | {{ $inquiry->listing->name }} |
@if($inquiry->connector_reference)
| **Booking reference** | {{ $inquiry->connector_reference }} |
@endif
@if($inquiry->total_amount && ! $isOrder)
| **Total** | {{ number_format($inquiry->total_amount, 2) }} {{ $inquiry->currency ?? 'NAD' }} |
@endif
</x-mail::table>

<x-inquiry-details :inquiry="$inquiry" />

@if($inquiry->message)
**Guest message:**

{{ $inquiry->message }}
@endif

Please confirm or decline within **3 days**. After that, the {{ $noun }} will expire automatically.

<x-mail::button :url="$confirmWithPaymentUrl" color="success">
Confirm &amp; ask for the deposit
</x-mail::button>

The guest gets one email — the confirmation, a payment button, and anything you would like
to add. You can write that message on the next page before anything is sent.

<x-mail::button :url="$confirmUrl" color="primary">
Confirm without asking for payment
</x-mail::button>

<x-mail::button :url="$cancelUrl" color="error">
Decline {{ $isOrder ? 'order' : 'booking' }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
