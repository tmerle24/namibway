<x-mail::message>
# New inquiry for {{ $listingName }}

You've received a new inquiry via NamibWay.

<x-mail::table>
| | |
|---|---|
| **Name** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?? '—' }} |
| **Check-in** | {{ $inquiry->check_in?->format('D, d M Y') ?? $inquiry->travel_dates ?? '—' }} |
| **Check-out** | {{ $inquiry->check_out?->format('D, d M Y') ?? '—' }} |
| **Guests** | {{ $inquiry->adults }} adult{{ $inquiry->adults !== 1 ? 's' : '' }}@if($inquiry->children > 0), {{ $inquiry->children }} child{{ $inquiry->children !== 1 ? 'ren' : '' }}@endif |
</x-mail::table>

@if ($inquiry->message)
**Message:**

{{ $inquiry->message }}
@endif

Please reply directly to {{ $inquiry->email }} to follow up.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
