@php
    use App\Enums\InquiryKind;

    $isOrder = $inquiry->kind === InquiryKind::Order;

    // A deposit is asked for against a stay. A table booking and an order never
    // become one, so the button would confirm the request and then tell the
    // business the calendar had refused it — see InquiryDecisions.
    $canAskForDeposit = $inquiry->kind->becomesAStay();
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
| **Property** | {{ $inquiry->sellerName() }} |
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

@if($canAskForDeposit)
<x-mail::button :url="$confirmWithPaymentUrl" color="success">
Confirm &amp; ask for the deposit
</x-mail::button>

The guest gets one email — the confirmation, a payment button, and anything you would like
to add. You can write that message on the next page before anything is sent.

<x-mail::button :url="$confirmUrl" color="primary">
Confirm without asking for payment
</x-mail::button>
@else
<x-mail::button :url="$confirmUrl" color="primary">
Confirm {{ $isOrder ? 'order' : 'booking' }}
</x-mail::button>
@endif

<x-mail::button :url="$cancelUrl" color="error">
Decline {{ $isOrder ? 'order' : 'booking' }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
