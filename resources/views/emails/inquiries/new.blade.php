<x-mail::message>
# New inquiry for {{ $listingName }}

You've received a new inquiry via NamibWay.

<x-mail::table>
| | |
|---|---|
| **Name** | {{ $inquiry->name }} |
| **Email** | {{ $inquiry->email }} |
| **Phone** | {{ $inquiry->phone ?? '—' }} |
| **Travel dates** | {{ $inquiry->travel_dates ?? '—' }} |
</x-mail::table>

@if ($inquiry->message)
**Message:**

{{ $inquiry->message }}
@endif

Please reply directly to {{ $inquiry->email }} to follow up.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
