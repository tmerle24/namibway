<x-mail::message>
# Partner opted out — follow-up call needed

**{{ $partner->name }}** clicked "not interested" on their outreach email and asked to be removed from NamibWay.

<x-mail::table>
| | |
|---|---|
| **Name** | {{ $partner->name }} |
| **Email** | {{ $partner->email ?? '—' }} |
| **Phone** | {{ $partner->phone ?? '—' }} |
| **Website** | {{ $partner->website ?? '—' }} |
@if($listing)
| **Listing** | {{ $listing->getTranslation('name', 'en') }} ({{ $listing->type->value }}) |
@endif
</x-mail::table>

Their listing has been unpublished automatically. A sales call to address concerns and re-confirm interest could win them back — please follow up.

@if($listing)
<x-mail::button :url="url('/admin/listings/' . $listing->id . '/edit')">
Open listing in admin
</x-mail::button>
@endif

Thanks,<br>
NamibWay
</x-mail::message>
