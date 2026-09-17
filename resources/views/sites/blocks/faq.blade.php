<section class="section {{ filled($data['background_image_id'] ?? null) ? 'section--photo' : '' }}" id="{{ $anchor }}">
    @include('sites.partials.section-photo')
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        {{-- <details>: opens without JavaScript, every answer stays in the page. --}}
        <div class="faq">
            @foreach ($data['items'] ?? [] as $item)
                <details class="faq__item reveal">
                    <summary>{{ $item['question'] }}</summary>
                    <p class="faq__a">{!! nl2br(e($item['answer'])) !!}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
