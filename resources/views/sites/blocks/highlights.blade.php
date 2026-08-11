@php
    $items = $data['items'] ?? [];
@endphp
<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        <div class="cards {{ count($items) % 3 === 0 ? 'cards--3' : '' }}">
            @foreach ($items as $item)
                <div class="card reveal">
                    <h3>{{ $item['title'] }}</h3>
                    @if (filled($item['text'] ?? null))
                        <p>{{ $item['text'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
