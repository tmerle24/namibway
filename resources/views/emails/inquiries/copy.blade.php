@php
    use App\Enums\InquiryKind;

    $isOrder = $inquiry->kind === InquiryKind::Order;
@endphp
<x-mail::message>
# Thanks, {{ $inquiry->name }}

We have passed your {{ $isOrder ? 'order' : 'enquiry' }} to **{{ $inquiry->listing->name }}**. They answer these
themselves, so the reply comes from them — usually within a day or two.

This is your copy of what you sent:

<x-mail::table>
| | |
|---|---|
| **Name** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?: '—' }} |
</x-mail::table>

<x-inquiry-details :inquiry="$inquiry" />

@if ($inquiry->message)
**Your message**

{{ $inquiry->message }}
@endif

Nothing is booked and nothing is owed. If you do not hear back, reply to this
email and we will follow it up for you.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
