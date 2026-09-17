@php
    $stats = collect($data['items'] ?? [])->filter(fn ($item) => isset($item['value']) && filled($item['label'] ?? null))->values();
    $ticker = collect($data['ticker'] ?? [])->filter(fn ($word) => filled($word))->values();
@endphp
@if ($stats->isNotEmpty() || $ticker->isNotEmpty())
    {{-- Not a section: no rule, no number, no anchor (StatsBlock::isSection). --}}
    <section class="stats">
        @if ($stats->isNotEmpty())
            <dl class="stats__grid wrap stats__grid--{{ $stats->count() }}">
                @foreach ($stats as $stat)
                    <div class="stats__item reveal">
                        <dt>{{ $stat['label'] }}</dt>
                        {{-- The real number is in the page; the script only
                             counts up to it. --}}
                        <dd><span data-count="{{ (int) $stat['value'] }}">{{ number_format((int) $stat['value']) }}</span>@if (filled($stat['unit'] ?? null))<small>{{ $stat['unit'] }}</small>@endif</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($ticker->isNotEmpty())
            {{-- Twice, so the loop has no seam; the copy is hidden from
                 screen readers. --}}
            <div class="ticker" aria-label="{{ $ticker->implode(', ') }}">
                <div class="ticker__track">
                    @foreach ([false, true] as $copy)
                        <p @if ($copy) aria-hidden="true" @endif>@foreach ($ticker as $word)<span>{{ $word }}</span>@endforeach</p>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endif
