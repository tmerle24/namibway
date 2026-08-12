@php
    use App\Sites\HighlightIcon;

    $items = $data['items'] ?? [];

    // All or nothing: one recognised phrase out of six is a stray picture
    // rather than a design, so the marks appear only where most of the column
    // can carry one. See App\Sites\HighlightIcon.
    $titles = array_map(fn (array $item): string => (string) ($item['title'] ?? ''), $items);
    $drawIcons = HighlightIcon::worthDrawing($titles);
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
                    @if ($drawIcons)
                        @php $icon = HighlightIcon::for((string) $item['title']); @endphp

                        @if ($icon)
                            @include('sites.partials.icon', ['icon' => $icon])
                        @else
                            <div class="card__icon card__icon--none" aria-hidden="true"></div>
                        @endif
                    @endif

                    <h3>{{ $item['title'] }}</h3>
                    @if (filled($item['text'] ?? null))
                        <p>{{ $item['text'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
