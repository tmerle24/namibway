<x-mail::message>
# New booking request — action required

You have received a booking request via NamibWay that requires your confirmation.

<x-mail::table>
| | |
|---|---|
| **Guest** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?? '—' }} |
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

@if($inquiry->message)
**Guest message:**

{{ $inquiry->message }}
@endif

Please confirm or decline within **3 days**. After that, the booking will expire automatically.

<x-mail::button :url="$confirmWithPaymentUrl" color="success">
Confirm &amp; ask for the deposit
</x-mail::button>

The guest gets one email — the confirmation, a payment button, and anything you would like
to add. You can write that message on the next page before anything is sent.

<x-mail::button :url="$confirmUrl" color="primary">
Confirm without asking for payment
</x-mail::button>

<x-mail::button :url="$cancelUrl" color="error">
Decline booking
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
