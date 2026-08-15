@php
    $photos = collect($data['image_ids'] ?? [])
        ->map(fn ($id) => $images->get((int) $id))
        ->filter();
@endphp
@if ($photos->isNotEmpty())
    <section class="section section--tint" id="{{ $anchor }}">
        <div class="wrap">
            @include('sites.partials.rule', ['label' => $definition->label()])

            @if (filled($data['heading'] ?? null))
                <h2 class="reveal">{{ $data['heading'] }}</h2>
            @endif

            <div class="grid-photos">
                @foreach ($photos as $photo)
                    {{-- Square crops at 400px: the grid is three across on a
                         desktop and two on a phone, so nothing here is ever
                         rendered wider than that, and asking for the original
                         would be paying for pixels nobody sees. --}}
                    <figure class="reveal">
                        <img src="{{ $photo->thumb(400) }}"
                             data-lb="{{ $photo->thumb(1200) }}"
                             @if ($srcset = $photo->srcset(400)) srcset="{{ $srcset }}" @endif
                             sizes="(min-width: 860px) 33vw, 50vw"
                             alt="{{ $photo->alt ?? $site->name }}"
                             loading="lazy" decoding="async">
                    </figure>
                @endforeach
            </div>
        </div>
    </section>
@endif
