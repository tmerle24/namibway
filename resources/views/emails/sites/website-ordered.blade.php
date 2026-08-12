@php
    /** @var \App\Models\WebsiteSubscription $subscription */
    $partner = $subscription->partner;
@endphp

<x-mail::message>
# A website has been ordered

<x-mail::table>
| | |
|---|---|
| **Business** | {{ $partner->name }} |
| **Email** | {{ $partner->email ?? '—' }} |
| **Phone** | {{ $partner->phone ?? '—' }} |
| **Ordered** | {{ $subscription->requested_at?->format('D, d M Y H:i') ?? '—' }} |
</x-mail::table>

@if ($subscription->note)
**What they wrote:**

{{ $subscription->note }}
@endif

Nothing is switched on yet. Invoice them, and set the subscription to active in the admin —
that is what unlocks the website button on their side.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
