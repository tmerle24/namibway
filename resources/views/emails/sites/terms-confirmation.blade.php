@php
    /** @var \App\Models\Site $site */
    $termsUrl = \App\Sites\LegalText::termsUrl();
@endphp

<x-mail::message>
# Your website is ready

Hello,

We have prepared a website for **{{ $site->name }}**. Have a look at it, and if it is right,
confirm it below — that is what puts it online under your name.

<x-mail::button :url="$site->publicUrl()">
Look at the website
</x-mail::button>

**What you are confirming.** That the content is right, that the pictures on it are yours or
yours to publish, and that you accept the privacy notice and legal notice we have prepared
for the site@if ($termsUrl), along with the {{ config('app.name') }} website terms@endif.
All of it can be changed afterwards — nothing here is fixed for ever.

@if ($termsUrl)
The website terms are here: [{{ $termsUrl }}]({{ $termsUrl }})
@endif

<x-mail::button :url="$confirmUrl" color="success">
Confirm and publish
</x-mail::button>

If something is wrong, reply to this email and tell us what to change. Nothing is published
until you press the button.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
