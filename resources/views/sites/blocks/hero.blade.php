@php
    use App\Sites\Rendering\SafeLink;

    $image = $images->get($data['image_id'] ?? null);
    $video = filled($data['video_key'] ?? null)
        ? \Illuminate\Support\Facades\Storage::disk('r2')->url($data['video_key'])
        : null;
    $card = $video !== null && ($data['video_layout'] ?? null) === 'card';

    /**
     * The buttons under the headline come from the site's own placement — by
     * default the enquiry button on a desktop and the business's own button
     * ("About us" until they change it) on both. A cta_label/cta_href typed
     * into the band still wins, and is shown on every screen: somebody typed
     * it here, into this band, which is as explicit as an instruction gets.
     */
    $typed = filled($data['cta_label'] ?? null) && filled($data['cta_href'] ?? null);
    $buttons = $actions->buttons('hero');
@endphp
<section class="hero {{ $image || $video ? '' : 'hero--plain' }} {{ $video ? 'hero--video' : '' }} {{ $card ? 'hero--card' : '' }}" id="top">
    @if ($image || $video)
        <div class="hero__media">
            {{-- The one eager image on the page, and the one allowed to be
                 large. Everything below the fold waits. --}}
            @if ($image)
                <img src="{{ $image->thumb(1600) }}"
                     @if ($srcset = $image->srcset(1600)) srcset="{{ $srcset }}" @endif
                     sizes="100vw"
                     alt="{{ $image->alt ?? $site->name }}"
                     width="{{ $image->width }}" height="{{ $image->height }}"
                     fetchpriority="high" decoding="async">
            @endif
            @if ($video && ! $card)
                {{-- No src until the script decides: data-src is only loaded
                     where motion is welcome and data is not being saved, so the
                     poster above is the whole opening for everybody else. --}}
                <video class="hero__video" data-src="{{ $video }}" muted loop playsinline preload="none" aria-hidden="true"></video>
            @endif
        </div>
    @endif

    @if ($video && $card)
        {{-- Outside the media layer, so on a wide screen it can sit above the shade. --}}
        <video class="hero__video" data-src="{{ $video }}" muted loop playsinline preload="none" aria-hidden="true"></video>

        @if (filled($data['video_caption'] ?? null))
            <p class="hero__videonote">{{ $data['video_caption'] }}</p>
        @endif
    @endif

    <div class="hero__body">
        @if (filled($data['eyebrow'] ?? null))
            <p class="hero__eyebrow">{{ $data['eyebrow'] }}</p>
        @endif

        {{-- Escaped, then given its line breaks back: this is display type set
             at 76px, and where a line turns is a decision the business makes,
             not the browser. Never markup — the text is theirs to type. --}}
        <h1>{!! nl2br(e($data['headline'] ?? $site->name)) !!}</h1>

        @if (filled($data['subline'] ?? null))
            <p class="hero__subline">{{ $data['subline'] }}</p>
        @endif

        @if ($typed || $buttons !== [])
            <div class="hero__cta">
                @if ($typed)
                    <a class="btn" href="{{ SafeLink::href($data['cta_href']) }}">{{ $data['cta_label'] }}</a>
                @endif

                {{-- The enquiry button is the filled one wherever it appears;
                     everything else is quieter, because a screen with two
                     equally loud buttons has asked the visitor to choose
                     between them. --}}
                @foreach ($buttons as $button)
                    <a class="btn {{ $button->isPrimary() && ! $typed ? '' : 'btn--light' }} {{ $button->deviceClass() }}"
                       href="{{ $button->href }}"
                       @if ($button->external) target="_blank" rel="noopener" @endif>
                        @if ($button->icon)
                            @include('sites.partials.action-icon', ['action' => $button->icon, 'class' => 'btn__icon'])
                        @endif
                        {{ $button->label }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($site->isEnterprise())
        <a class="hero__scroll" href="#s1" aria-label="Scroll down"></a>
    @endif
</section>
