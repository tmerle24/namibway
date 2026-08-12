@php
    use App\Sites\Rendering\SafeLink;

    $image = $images->get($data['image_id'] ?? null);

    /**
     * The first screen gets the primary action, whether or not anybody has
     * typed one in. Generation leaves `cta_label`/`cta_href` empty — nothing
     * knows the anchors at that point — so a generated site used to open with a
     * headline and no way to act on it; the visitor's only route to the form
     * was to scroll or find the burger. An owner who sets their own label and
     * target still wins over the derived one.
     */
    $ctaLabel = filled($data['cta_label'] ?? null) && filled($data['cta_href'] ?? null)
        ? $data['cta_label']
        : $actions->primaryLabel;

    $ctaHref = filled($data['cta_label'] ?? null) && filled($data['cta_href'] ?? null)
        ? SafeLink::href($data['cta_href'])
        : ($actions->primaryAnchor ? '#'.$actions->primaryAnchor : null);
@endphp
<section class="hero {{ $image ? '' : 'hero--plain' }}" id="top">
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

        @if ($ctaHref || $actions->whatsapp)
            <div class="hero__cta">
                @if ($ctaHref)
                    <a class="btn" href="{{ $ctaHref }}">{{ $ctaLabel }}</a>
                @endif

                {{-- Second, and never the accent one: WhatsApp is how most of
                     this market actually answers, but it is a way of asking,
                     not the thing being asked for. --}}
                @if ($actions->whatsapp)
                    <a class="btn btn--light" href="{{ $actions->whatsapp }}" target="_blank" rel="noopener">
                        @include('sites.partials.action-icon', ['action' => 'whatsapp', 'class' => 'btn__icon'])
                        WhatsApp
                    </a>
                @endif
            </div>
        @endif
    </div>
</section>
