@php
    $image = $images->get($data['image_id'] ?? null);
    $imageOnLeft = ($data['image_side'] ?? 'right') === 'left';
@endphp
<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $data['heading'] ?? $definition->label()])

        <div class="{{ $image ? 'split' : '' }} reveal">
            @if ($image && $imageOnLeft)
                <figure class="figure figure--lb" style="margin:0">
                    <img src="{{ $image->thumb(800) }}"
                         data-lb="{{ $image->thumb(1200) }}"
                         @if ($srcset = $image->srcset(800)) srcset="{{ $srcset }}" @endif
                         sizes="(min-width: 860px) 50vw, 100vw"
                         alt="{{ $image->alt ?? $site->name }}"
                         loading="lazy" decoding="async">
                </figure>
            @endif

            <div>
                @if (filled($data['heading'] ?? null))
                    <h2>{{ $data['heading'] }}</h2>
                @endif

                <div class="prose">{!! \App\Sites\Rendering\StoryText::html($data['body'] ?? null) !!}</div>
            </div>

            @if ($image && ! $imageOnLeft)
                <figure class="figure figure--lb" style="margin:0">
                    <img src="{{ $image->thumb(800) }}"
                         data-lb="{{ $image->thumb(1200) }}"
                         @if ($srcset = $image->srcset(800)) srcset="{{ $srcset }}" @endif
                         sizes="(min-width: 860px) 50vw, 100vw"
                         alt="{{ $image->alt ?? $site->name }}"
                         loading="lazy" decoding="async">
                </figure>
            @endif
        </div>
    </div>
</section>
