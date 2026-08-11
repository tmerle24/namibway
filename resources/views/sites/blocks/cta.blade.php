<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            @if (filled($data['text'] ?? null))
                <p class="prose">{{ $data['text'] }}</p>
            @endif

            @if (filled($data['label'] ?? null))
                <p style="margin-top: var(--s5)">
                    <a class="btn" href="{{ \App\Sites\Rendering\SafeLink::href($data['href'] ?? null) }}">{{ $data['label'] }}</a>
                </p>
            @endif
        </div>
    </div>
</section>
