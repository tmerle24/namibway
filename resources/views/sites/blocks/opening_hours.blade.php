<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            <div class="rows">
                @foreach ($data['days'] ?? [] as $day)
                    <div class="row">
                        <span class="row__main">{{ $day['day'] }}</span>
                        <span class="row__value">{{ $day['hours'] }}</span>
                    </div>
                @endforeach
            </div>

            @if (filled($data['note'] ?? null))
                <p class="note">{{ $data['note'] }}</p>
            @endif
        </div>
    </div>
</section>
