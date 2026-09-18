@php
    use App\Sites\Rendering\RouteMap;

    $stops = collect($data['items'] ?? [])
        ->filter(fn ($s) => filled($s['name'] ?? null) && is_numeric($s['lat'] ?? null) && is_numeric($s['lng'] ?? null))
        ->values();
    $map = $stops->count() >= 2
        ? RouteMap::for((string) ($data['country'] ?? 'NA'), $stops->map(fn ($s) => ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng']])->all())
        : null;
@endphp
@if ($map)
    <section class="section section--map" id="{{ $anchor }}">
        <div class="wrap routemap">
            <div class="routemap__text">
                @include('sites.partials.rule', ['label' => $definition->label()])

                @if (filled($data['heading'] ?? null))
                    <h2 class="reveal">{{ $data['heading'] }}</h2>
                @endif

                @if (filled($data['intro'] ?? null))
                    <p class="lead reveal">{{ $data['intro'] }}</p>
                @endif

                {{-- The stops as text too: the map is a picture, this is what a
                     screen reader and a search engine get. A stop the road
                     comes back to is listed once. --}}
                <ol class="routemap__legend reveal">
                    @foreach ($stops->unique('name') as $stop)
                        <li>
                            @if (filled($stop['label'] ?? null))<span>{{ $stop['label'] }}</span>@endif
                            {{ $stop['name'] }}
                        </li>
                    @endforeach
                </ol>

                @if (filled($data['note'] ?? null))
                    <p class="note reveal">{{ $data['note'] }}</p>
                @endif
            </div>

            <figure class="routemap__paper reveal">
                <svg class="map" viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" role="img"
                     aria-label="{{ $data['title'] ?? 'Route map' }}: {{ $stops->pluck('name')->unique()->implode(', ') }}">
                    <defs>
                        {{-- A little wobble, so the border reads as inked by hand. --}}
                        <filter id="rm-ink"><feTurbulence type="fractalNoise" baseFrequency=".035" numOctaves="2" seed="7"/><feDisplacementMap in="SourceGraphic" scale="5"/></filter>
                        <path id="rm-road" d="{{ $map['route'] }}"/>
                        <mask id="rm-reveal" maskUnits="userSpaceOnUse"><path d="{{ $map['route'] }}" class="map__draw" pathLength="1"/></mask>
                    </defs>

                    @for ($g = 1; $g < 5; $g++)
                        <path class="map__grid" d="M0 {{ round($map['height'] * $g / 5) }}H{{ $map['width'] }}M{{ round($map['width'] * $g / 5) }} 0V{{ $map['height'] }}"/>
                    @endfor

                    <path class="map__land" d="{{ $map['outline'] }}" filter="url(#rm-ink)" pathLength="1"/>
                    <use href="#rm-road" class="map__route" mask="url(#rm-reveal)"/>

                    {{-- The traveller: a light that runs the road once it is drawn. --}}
                    <circle class="map__traveller" r="6">
                        <animateMotion dur="16s" begin="4.5s" repeatCount="indefinite" rotate="0"><mpath href="#rm-road"/></animateMotion>
                    </circle>

                    @php $labelled = []; @endphp
                    @foreach ($map['points'] as $i => $p)
                        @php
                            $name = $stops[$i]['name'];
                            $right = $p['x'] > $map['width'] * 0.66;
                        @endphp
                        <g class="map__pin" style="--i: {{ $i }}" transform="translate({{ $p['x'] }} {{ $p['y'] }})">
                            <circle r="{{ $i === 0 ? 8 : 5.5 }}" @class(['map__start' => $i === 0])/>
                            @unless (in_array($name, $labelled, true))
                                <text x="{{ $right ? -12 : 12 }}" y="5" @if ($right) text-anchor="end" @endif>{{ $name }}</text>
                                @php $labelled[] = $name; @endphp
                            @endunless
                        </g>
                    @endforeach

                    {{-- Compass rose, bottom left, in the ocean. --}}
                    <g class="map__compass" transform="translate(58 {{ $map['height'] - 70 }})">
                        <circle r="30"/>
                        <path d="M0 -38L6 -6L38 0L6 6L0 38L-6 6L-38 0L-6 -6Z"/>
                        <path class="map__compass-n" d="M0 -38L6 -6L0 0L-6 -6Z"/>
                        <text y="-44" text-anchor="middle">N</text>
                    </g>

                    @if (filled($data['title'] ?? null))
                        <g class="map__cartouche" transform="translate({{ $map['width'] - 24 }} {{ $map['height'] - 40 }})">
                            <text text-anchor="end">{{ $data['title'] }}</text>
                            <path d="M-{{ min(300, mb_strlen($data['title']) * 9) }} 12H0"/>
                        </g>
                    @endif
                </svg>
            </figure>
        </div>
    </section>
@endif
