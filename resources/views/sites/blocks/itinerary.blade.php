<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        @if (filled($data['heading'] ?? null))
            <h2 class="reveal">{{ $data['heading'] }}</h2>
        @endif

        @if (filled($data['intro'] ?? null))
            <p class="lead reveal">{{ $data['intro'] }}</p>
        @endif

        <ol class="trip">
            @foreach ($data['items'] ?? [] as $stop)
                <li class="trip__stop reveal">
                    <span class="trip__day">{{ $stop['day'] ?? '' }}</span>
                    <div class="trip__body">
                        <h3>{{ $stop['title'] }}</h3>
                        @if (filled($stop['drive'] ?? null))
                            <p class="trip__drive">{{ $stop['drive'] }}</p>
                        @endif
                        @if (filled($stop['text'] ?? null))
                            <p class="trip__text">{!! nl2br(e($stop['text'])) !!}</p>
                        @endif
                        @if (filled($stop['stay'] ?? null))
                            <p class="trip__stay">{{ $stop['stay'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        @if (filled($data['note'] ?? null))
            <p class="note reveal">{{ $data['note'] }}</p>
        @endif
    </div>
</section>
