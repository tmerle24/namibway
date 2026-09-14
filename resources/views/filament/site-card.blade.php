@php
    /** @var \App\Models\Site $site */
    /** @var \App\Sites\Rendering\BusinessCard $card */
    $url = $card->url();
    $qr = $site->pageUrl('card/qr');
    // The draft token is already on both URLs (Site::pageUrl) — a code printed
    // from a draft has to be scannable while the site is still being reviewed.
    $download = $qr.(str_contains($qr, '?') ? '&' : '?').'download=1';
@endphp
<div class="space-y-4 text-sm">
    <div>
        <div class="font-medium text-gray-950 dark:text-white">The card's address</div>
        <a href="{{ $url }}" target="_blank" rel="noopener"
           class="break-all text-primary-600 underline dark:text-primary-400">{{ $url }}</a>
    </div>

    <div class="flex flex-wrap items-center gap-6">
        <img src="{{ $qr }}" alt="QR code for the business card" width="180" height="180"
             class="rounded-lg bg-white p-2 ring-1 ring-gray-200 dark:ring-white/10">

        <div class="space-y-2">
            <a href="{{ $download }}" class="text-primary-600 underline dark:text-primary-400">Download the code (PNG)</a>
            <p class="text-gray-500 dark:text-gray-400">
                Print it at 2 cm or larger. It points at the page above, so the card keeps working when the
                number or the website changes — nothing has to be reprinted.
            </p>
        </div>
    </div>

    @unless ($site->isPublished())
        <p class="text-warning-600 dark:text-warning-400">
            This site is still a draft, so the code carries its preview token. Regenerate it after publishing,
            or the printed card will stop working when the draft link is retired.
        </p>
    @endunless
</div>
