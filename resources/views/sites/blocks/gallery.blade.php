@php
    use App\Sites\Blocks\GalleryBlock;

    $photos = collect($data['image_ids'] ?? [])
        ->map(fn ($id) => $images->get((int) $id))
        ->filter()
        ->values();

    // Big first tile once there are enough pictures to fill around it.
    $feature = $photos->count() >= 5;
    $hidden = max(0, $photos->count() - GalleryBlock::VISIBLE);
@endphp
@if ($photos->isNotEmpty())
    <section class="section section--tint" id="{{ $anchor }}">
        <div class="wrap">
            @include('sites.partials.rule', ['label' => $definition->label()])

            @if (filled($data['heading'] ?? null))
                <h2 class="reveal">{{ $data['heading'] }}</h2>
            @endif

            <div class="grid-photos {{ $feature ? 'grid-photos--feature' : '' }}" id="{{ $anchor }}-photos">
                @foreach ($photos as $i => $photo)
                    @php $width = $feature && $i === 0 ? 800 : 400; @endphp
                    {{-- Past VISIBLE: hidden under .js until "Show all", and lazy,
                         so the first view never fetches them. --}}
                    <figure class="reveal {{ $i >= GalleryBlock::VISIBLE ? 'is-more' : '' }}">
                        <img src="{{ $photo->thumb($width) }}"
                             data-lb="{{ $photo->thumb(1200) }}"
                             @if ($srcset = $photo->srcset($width)) srcset="{{ $srcset }}" @endif
                             sizes="{{ $width === 800 ? '(min-width: 860px) 66vw, 100vw' : '(min-width: 860px) 33vw, 50vw' }}"
                             alt="{{ $photo->alt ?? $site->name }}"
                             loading="lazy" decoding="async">
                    </figure>
                @endforeach
            </div>

            @if ($hidden > 0)
                {{-- Unhidden by the script; without it every picture is already shown. --}}
                <div class="gallery-more" data-gallery-more="{{ $anchor }}-photos" hidden>
                    <button type="button" class="btn btn--ghost">Show all {{ $photos->count() }} photos</button>
                </div>
            @endif
        </div>
    </section>
@endif
