<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            <div class="rows">
                @foreach ($data['items'] ?? [] as $item)
                    <div class="row">
                        <span class="row__main">
                            <span class="row__name">{{ $item['name'] }}</span>
                            @if (filled($item['description'] ?? null))
                                <span class="row__note">{{ $item['description'] }}</span>
                            @endif
                        </span>
                        @if (filled($item['price'] ?? null))
                            <span class="row__value">{{ $item['price'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            @if (filled($data['note'] ?? null))
                <p class="note">{{ $data['note'] }}</p>
            @endif
        </div>
    </div>
</section>
