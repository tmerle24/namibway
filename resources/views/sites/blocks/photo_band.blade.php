@php
    $image = $images->get($data['image_id'] ?? null);
@endphp
@if ($image)
    {{-- Not a section: no rule, no number, no anchor (PhotoBandBlock::isSection). --}}
    <section class="band">
        <img class="band__img reveal"
             src="{{ $image->thumb(1600) }}"
             @if ($srcset = $image->srcset(1600)) srcset="{{ $srcset }}" @endif
             sizes="100vw"
             alt="{{ $image->alt ?? $site->name }}"
             loading="lazy" decoding="async">

        @if (filled($data['statement'] ?? null) || filled($data['caption'] ?? null))
            <div class="band__body wrap reveal">
                @if (filled($data['statement'] ?? null))
                    <p class="band__statement">{{ $data['statement'] }}</p>
                @endif
                @if (filled($data['caption'] ?? null))
                    <p class="band__caption">{{ $data['caption'] }}</p>
                @endif
            </div>
        @endif
    </section>
@endif
