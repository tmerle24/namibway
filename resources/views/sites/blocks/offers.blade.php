@php
    $items = $data['items'] ?? [];
    $enquire = $actions->enquiryHref();
    $button = filled($data['button_label'] ?? null) ? $data['button_label'] : 'Enquire';
@endphp
<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        @if (filled($data['intro'] ?? null))
            <p class="lead reveal">{{ $data['intro'] }}</p>
        @endif

        <div class="offers {{ count($items) >= 3 ? 'offers--3' : '' }}">
            @foreach ($items as $item)
                @php $image = $images->get($item['image_id'] ?? null); @endphp
                <article class="offer-card reveal">
                    @if ($image)
                        <div class="offer-card__media">
                            <img src="{{ $image->thumb(600) }}"
                                 @if ($srcset = $image->srcset(600)) srcset="{{ $srcset }}" @endif
                                 sizes="(min-width: 980px) 33vw, (min-width: 640px) 50vw, 100vw"
                                 alt="{{ $image->alt ?? $item['title'] }}"
                                 loading="lazy" decoding="async">
                        </div>
                    @endif

                    <div class="offer-card__body">
                        @if (filled($item['duration'] ?? null))
                            <p class="offer-card__meta">{{ $item['duration'] }}</p>
                        @endif

                        <h3>{{ $item['title'] }}</h3>

                        @if (filled($item['text'] ?? null))
                            <p class="offer-card__text">{!! nl2br(e($item['text'])) !!}</p>
                        @endif

                        <div class="offer-card__foot">
                            @if (filled($item['price'] ?? null))
                                <p class="offer-card__price">{{ $item['price'] }}</p>
                            @endif

                            {{-- data-enquire: the title goes into the form's message; data-enquire-listing
                                 chooses the tour where the form lists them (partials/motion). --}}
                            @if ($enquire)
                                <a class="btn btn--ghost" href="{{ $enquire }}" data-enquire="{{ $item['title'] }}" data-enquire-listing="{{ $item['listing_slug'] ?? '' }}">{{ $button }}</a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
