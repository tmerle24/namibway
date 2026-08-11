@php
    $image = $images->get($data['image_id'] ?? null);
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $data['eyebrow'] ?? $definition->label()])

        <div class="split reveal">
            <div>
                @if (filled($data['heading'] ?? null))
                    <h2>{{ $data['heading'] }}</h2>
                @endif

                {{-- Already sanitised on the way in through the same purifier
                     allow-list as a listing description — see SiteBlock and
                     Listing::sanitizeRichText. --}}
                <div class="prose">{!! $data['body'] !!}</div>
            </div>

            @if ($image)
                <figure class="figure" style="margin:0">
                    <img src="{{ $image->thumb(800) }}"
                         @if ($srcset = $image->srcset(800)) srcset="{{ $srcset }}" @endif
                         sizes="(min-width: 860px) 50vw, 100vw"
                         alt="{{ $image->alt ?? $site->name }}"
                         loading="lazy" decoding="async">
                </figure>
            @endif
        </div>
    </div>
</section>
