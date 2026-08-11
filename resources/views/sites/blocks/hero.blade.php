@php
    $image = $images->get($data['image_id'] ?? null);
@endphp
<section class="hero" id="top">
    @if ($image)
        <div class="hero__media">
            {{-- The one eager image on the page, and the one allowed to be
                 large. Everything below the fold waits. --}}
            <img src="{{ $image->thumb(1600) }}"
                 @if ($srcset = $image->srcset(1600)) srcset="{{ $srcset }}" @endif
                 sizes="100vw"
                 alt="{{ $image->alt ?? $site->name }}"
                 width="{{ $image->width }}" height="{{ $image->height }}"
                 fetchpriority="high" decoding="async">
        </div>
    @endif

    <div class="hero__body">
        @if (filled($data['eyebrow'] ?? null))
            <p class="hero__eyebrow">{{ $data['eyebrow'] }}</p>
        @endif

        <h1>{{ $data['headline'] ?? $site->name }}</h1>

        @if (filled($data['subline'] ?? null))
            <p class="hero__subline">{{ $data['subline'] }}</p>
        @endif

        @if (filled($data['cta_label'] ?? null) && filled($data['cta_href'] ?? null))
            <div class="hero__cta">
                <a class="btn" href="{{ \App\Sites\Rendering\SafeLink::href($data['cta_href']) }}">{{ $data['cta_label'] }}</a>
            </div>
        @endif
    </div>
</section>
