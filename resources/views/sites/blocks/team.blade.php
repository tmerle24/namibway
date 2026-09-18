@php
    $people = $data['items'] ?? [];
    // One person is a portrait beside their story; several are a row.
    $solo = count($people) === 1;
@endphp
<section class="section section--tint {{ $solo ? 'section--leader' : '' }} {{ filled($data['background_image_id'] ?? null) ? 'section--photo' : '' }}" id="{{ $anchor }}">
    @include('sites.partials.section-photo')
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        @if (filled($data['intro'] ?? null))
            <p class="lead reveal">{{ $data['intro'] }}</p>
        @endif

        <div class="team {{ $solo ? 'team--solo' : '' }}">
            @foreach ($people as $person)
                @php $image = $images->get($person['image_id'] ?? null); @endphp
                <article class="person reveal">
                    @if ($image)
                        <figure class="person__photo figure--lb">
                            <img src="{{ $image->thumb($solo ? 800 : 400) }}"
                                 data-lb="{{ $image->thumb(1200) }}"
                                 @if ($srcset = $image->srcset($solo ? 800 : 400)) srcset="{{ $srcset }}" @endif
                                 sizes="{{ $solo ? '(min-width: 860px) 40vw, 100vw' : '(min-width: 980px) 33vw, (min-width: 640px) 50vw, 100vw' }}"
                                 alt="{{ $image->alt ?? $person['name'] }}"
                                 loading="lazy" decoding="async">
                            <figcaption class="person__caption">{{ $person['name'] }}</figcaption>
                        </figure>
                    @endif

                    <div class="person__body">
                        @if (filled($person['role'] ?? null))
                            <p class="person__role">{{ $person['role'] }}</p>
                        @endif
                        <h3 class="person__name">{{ $person['name'] }}</h3>
                        @if (filled($person['text'] ?? null))
                            <p class="person__text">{!! nl2br(e($person['text'])) !!}</p>
                        @endif
                        @if (! empty($person['facts']))
                            <dl class="person__facts">
                                @foreach ($person['facts'] as $fact)
                                    <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
