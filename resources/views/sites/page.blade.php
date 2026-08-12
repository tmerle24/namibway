@php
    /** @var \App\Models\Site $site */
    /** @var \App\Models\SitePage $page */
    /** @var \Illuminate\Support\Collection<int, \App\Models\SiteBlock> $blocks */

    $title = $page->title ?: $site->name;
    $description = $page->meta_description;

    $hero = $blocks->firstWhere('type', 'hero');
    $heroImage = $hero ? $images->get($hero->data['image_id'] ?? null) : null;

    // The number in the signature rule counts content sections only — the hero
    // announces itself and the footer is not a section.
    $counter = 0;
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif

    @unless ($site->isPublished())
        {{-- Belt and braces with the token check in the controller. A draft is
             research about somebody's business, not publication under their name. --}}
        <meta name="robots" content="noindex, nofollow">
    @endunless

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    @if ($description)
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if ($heroImage)
        <meta property="og:image" content="{{ url($heroImage->thumb(1600)) }}">
    @endif

    {{-- The hero is the largest thing on the page and the only image that
         blocks a meaningful paint, so it is the only one told about early. --}}
    @if ($heroImage)
        <link rel="preload" as="image" href="{{ $heroImage->thumb(1600) }}" fetchpriority="high">
    @endif

    @include('sites.partials.styles')
</head>
<body id="top">
    <script>document.documentElement.classList.add('js');</script>

    @include('sites.partials.nav', ['hasHero' => $hero !== null])

    <main>
        @foreach ($blocks as $block)
            @php
                $definition = $block->definition();
                $numbered = ! in_array($block->type, ['hero', 'footer'], true);

                if ($numbered) {
                    $counter++;
                }
            @endphp

            @include('sites.blocks.'.$block->type, [
                'block' => $block,
                'data' => $block->data,
                'definition' => $definition,
                'number' => $numbered ? str_pad((string) $counter, 2, '0', STR_PAD_LEFT) : null,
                'anchor' => 's'.$counter,
            ])
        @endforeach
    </main>

    @include('sites.partials.motion')
</body>
</html>
