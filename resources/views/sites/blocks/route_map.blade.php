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
    <section class="section section--map {{ filled($data['background_image_id'] ?? null) ? 'section--photo' : '' }}" id="{{ $anchor }}">
        @include('sites.partials.section-photo')
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

            @php $paper = $images->get($data['paper_image_id'] ?? null); @endphp
            <div class="routemap__sheet reveal"><div class="routemap__burn">
            <figure class="routemap__paper" @if ($paper) style="--paper: url('{{ $paper->thumb(1200) }}')" @endif>
                <svg class="map" viewBox="0 0 {{ $map['width'] }} {{ $map['height'] }}" role="img"
                     aria-label="{{ $data['title'] ?? 'Route map' }}: {{ $stops->pluck('name')->unique()->implode(', ') }}">
                    <defs>
                        {{-- A little wobble, so every line reads as inked by hand. --}}
                        <filter id="rm-ink"><feTurbulence type="fractalNoise" baseFrequency=".035" numOctaves="2" seed="7"/><feDisplacementMap in="SourceGraphic" scale="4"/></filter>
                        {{-- The sand sea, the way old charts shade dunes. --}}
                        <pattern id="rm-dunes" width="12" height="8" patternUnits="userSpaceOnUse"><path d="M1 7q3-5 6 0M7 3q2-3 4 0" class="map__dune"/></pattern>
                        <path id="rm-road" d="{{ $map['route'] }}"/>
                        <mask id="rm-reveal" maskUnits="userSpaceOnUse"><path d="{{ $map['route'] }}" class="map__draw" pathLength="1"/></mask>
                    </defs>

                    @for ($g = 1; $g < 6; $g++)
                        <path class="map__grid" d="M0 {{ round($map['height'] * $g / 6) }}H{{ $map['width'] }}M{{ round($map['width'] * $g / 6) }} 0V{{ $map['height'] }}"/>
                    @endfor

                    {{-- The coast washed in blue, then the land. --}}
                    <path class="map__coast" d="{{ $map['outline'] }}"/>
                    <path class="map__land" d="{{ $map['outline'] }}" pathLength="1"/>
                    @if ($map['sand'])
                        <path class="map__sand" d="{{ $map['sand'] }}" fill="url(#rm-dunes)"/>
                    @endif
                    @foreach ($map['rivers'] as $river)
                        <path class="map__river" d="{{ $river }}"/>
                    @endforeach
                    @foreach ($map['pans'] as $pan)
                        <ellipse class="map__pan" cx="{{ $pan['x'] }}" cy="{{ $pan['y'] }}" rx="{{ $pan['rx'] }}" ry="{{ $pan['ry'] }}"/>
                        <text class="map__small" x="{{ $pan['x'] }}" y="{{ $pan['y'] + $pan['ry'] + 12 }}" text-anchor="middle">{{ $pan['name'] }}</text>
                    @endforeach
                    @foreach ($map['mountains'] as $m)
                        <path class="map__peak" d="M{{ $m['x'] - 9 }} {{ $m['y'] + 5 }}l6-10 4 5 3-4 5 9"/>
                    @endforeach
                    @foreach ($map['labels'] as $label)
                        <text class="map__country" x="{{ $label['x'] }}" y="{{ $label['y'] }}" text-anchor="middle">{{ $label['name'] }}</text>
                    @endforeach
                    @if (strtoupper((string) ($data['country'] ?? 'NA')) === 'NA')
                        <text class="map__sea" transform="translate(44 {{ round($map['height'] * 0.5) }}) rotate(-68)">Atlantic Ocean</text>
                        @if ($map['sand'])
                            <text class="map__small" transform="translate({{ round($map['width'] * 0.19) }} {{ round($map['height'] * 0.72) }}) rotate(-72)">Namib Sand Sea</text>
                        @endif
                    @endif

                    {{-- Three claw marks across the chart, as if something with a
                         paw had a go at it. Enterprise only (the showcase shows it). --}}
                    <filter id="rm-rough" x="-10%" y="-10%" width="120%" height="120%"><feTurbulence type="fractalNoise" baseFrequency=".35" numOctaves="2" seed="5"/><feDisplacementMap in="SourceGraphic" scale="3.5"/></filter>
                    <g class="map__claw" filter="url(#rm-rough)" transform="translate({{ round($map['width'] * 0.75) }} {{ round($map['height'] * 0.13) }})">
                            <path class="lip" d="M2.2 -1.2 L3.6 4.8 L5.1 10.8 L6.7 16.8 L8.4 22.7 L10.2 28.5 L12.0 34.3 L14.0 40.1 L16.2 45.7 L18.4 51.3 L20.9 56.8 L23.5 62.2 L26.2 67.6 L29.1 72.8 L32.2 78.0 L35.5 83.0 L38.9 88.0 L42.4 92.9 L46.1 97.8 L49.9 102.5 L53.9 107.2 L58.0 111.8 L61.9 116.6 L65.8 121.3 L69.8 125.9 L69.8 125.9 L65.8 121.3 L61.9 116.6 L58.0 111.8 L54.4 107.0 L50.7 102.1 L47.2 97.2 L43.7 92.2 L40.4 87.2 L37.1 82.2 L34.0 77.0 L31.0 71.8 L28.1 66.6 L25.4 61.2 L22.8 55.8 L20.3 50.3 L18.0 44.8 L15.8 39.1 L13.7 33.5 L11.7 27.8 L9.7 22.0 L7.8 16.2 L6.0 10.4 L4.1 4.6 L2.2 -1.2Z"/>
                            <path class="lip" d="M26.2 2.4 L27.8 9.5 L29.6 16.4 L31.5 23.4 L33.4 30.3 L35.4 37.1 L37.6 43.9 L39.9 50.6 L42.4 57.2 L45.0 63.7 L47.9 70.2 L50.9 76.5 L54.1 82.7 L57.5 88.8 L61.1 94.8 L64.9 100.7 L68.9 106.6 L73.0 112.3 L77.3 117.9 L81.8 123.5 L86.4 129.0 L91.2 134.3 L95.8 139.8 L100.4 145.3 L105.1 150.7 L105.1 150.7 L100.4 145.3 L95.8 139.8 L91.2 134.3 L86.9 128.7 L82.6 123.1 L78.4 117.4 L74.3 111.6 L70.3 105.8 L66.5 99.9 L62.8 93.9 L59.3 87.8 L56.0 81.7 L52.8 75.5 L49.8 69.1 L46.9 62.7 L44.2 56.2 L41.7 49.7 L39.2 43.0 L36.9 36.3 L34.7 29.6 L32.5 22.8 L30.4 16.0 L28.4 9.2 L26.2 2.4Z"/>
                            <path class="lip" d="M52.2 6.3 L53.5 11.9 L55.0 17.5 L56.5 23.0 L58.1 28.5 L59.8 34.0 L61.5 39.4 L63.4 44.7 L65.4 50.0 L67.6 55.2 L69.8 60.3 L72.3 65.4 L74.8 70.4 L77.6 75.3 L80.5 80.1 L83.5 84.8 L86.6 89.5 L90.0 94.0 L93.4 98.6 L96.9 103.0 L100.6 107.4 L104.4 111.8 L108.0 116.2 L111.6 120.6 L115.3 125.0 L115.3 125.0 L111.6 120.6 L108.0 116.2 L104.4 111.8 L101.0 107.2 L97.7 102.6 L94.4 98.0 L91.2 93.4 L88.1 88.7 L85.1 83.9 L82.2 79.1 L79.4 74.3 L76.8 69.4 L74.2 64.4 L71.8 59.3 L69.5 54.2 L67.3 49.0 L65.2 43.8 L63.2 38.5 L61.3 33.2 L59.4 27.8 L57.6 22.5 L55.8 17.1 L54.0 11.7 L52.2 6.3Z"/>
                            <path class="gouge" d="M0.0 0.0 L1.1 6.5 L2.5 12.8 L4.1 19.1 L5.7 25.3 L7.4 31.4 L9.3 37.5 L11.3 43.5 L13.5 49.4 L15.9 55.3 L18.4 61.0 L21.1 66.6 L24.0 72.2 L27.1 77.6 L30.4 83.0 L33.8 88.2 L37.5 93.3 L41.3 98.4 L45.3 103.4 L49.4 108.2 L53.7 113.0 L58.2 117.7 L62.3 122.6 L66.3 127.5 L70.4 132.4 L70.4 132.4 L66.3 127.5 L62.3 122.6 L58.2 117.7 L54.9 112.4 L51.4 107.2 L47.9 101.9 L44.5 96.7 L41.1 91.4 L37.9 86.0 L34.7 80.6 L31.7 75.2 L28.8 69.6 L25.9 64.1 L23.2 58.4 L20.6 52.7 L18.1 47.0 L15.7 41.2 L13.4 35.3 L11.2 29.5 L9.0 23.6 L6.8 17.6 L4.6 11.7 L2.4 5.8 L0.0 0.0Z"/>
                            <path class="gouge" d="M24.0 3.6 L25.4 11.1 L27.1 18.5 L28.9 25.8 L30.8 33.0 L32.8 40.2 L35.0 47.3 L37.4 54.3 L39.9 61.2 L42.6 68.0 L45.6 74.7 L48.7 81.3 L52.1 87.8 L55.7 94.1 L59.5 100.3 L63.5 106.5 L67.8 112.5 L72.2 118.4 L76.8 124.2 L81.6 129.9 L86.6 135.5 L91.8 141.0 L96.6 146.7 L101.4 152.4 L106.2 158.1 L106.2 158.1 L101.4 152.4 L96.6 146.7 L91.8 141.0 L87.8 134.9 L83.6 128.8 L79.4 122.8 L75.4 116.7 L71.4 110.5 L67.6 104.3 L63.9 98.0 L60.3 91.7 L56.9 85.2 L53.6 78.7 L50.4 72.1 L47.4 65.5 L44.5 58.8 L41.8 52.0 L39.1 45.1 L36.5 38.2 L34.0 31.3 L31.6 24.3 L29.1 17.4 L26.7 10.4 L24.0 3.6Z"/>
                            <path class="gouge" d="M50.0 7.5 L51.1 13.5 L52.4 19.4 L53.8 25.3 L55.4 31.1 L57.0 36.8 L58.8 42.5 L60.7 48.1 L62.7 53.6 L64.9 59.0 L67.3 64.4 L69.8 69.6 L72.5 74.8 L75.4 79.9 L78.5 84.8 L81.7 89.7 L85.1 94.5 L88.7 99.3 L92.4 103.9 L96.2 108.5 L100.2 112.9 L104.5 117.3 L108.2 121.9 L112.0 126.5 L115.7 131.1 L115.7 131.1 L112.0 126.5 L108.2 121.9 L104.5 117.3 L101.4 112.3 L98.2 107.4 L95.0 102.5 L91.8 97.6 L88.8 92.6 L85.8 87.6 L82.8 82.5 L80.0 77.4 L77.3 72.3 L74.7 67.1 L72.1 61.8 L69.7 56.5 L67.3 51.1 L65.1 45.7 L62.9 40.3 L60.7 34.8 L58.6 29.3 L56.5 23.8 L54.5 18.3 L52.3 12.9 L50.0 7.5Z"/>
                    </g>

                    <use href="#rm-road" class="map__route" mask="url(#rm-reveal)"/>

                    {{-- The traveller: a light that runs the road once it is drawn. --}}
                    <circle class="map__traveller" r="6">
                        <animateMotion dur="16s" begin="4.5s" repeatCount="indefinite" rotate="0"><mpath href="#rm-road"/></animateMotion>
                    </circle>

                    {{-- X marks the last stop that is not the start. --}}
                    @php $x = collect($map['points'])->reject(fn ($p) => $p == $map['points'][0])->last(); @endphp
                    @if ($x)
                        <path class="map__x" d="M{{ $x['x'] - 10 }} {{ $x['y'] - 10 }}l20 20m0 -20l-20 20"/>
                    @endif

                    @php $labelled = []; $number = 0; @endphp
                    @foreach ($map['points'] as $i => $p)
                        @php
                            $name = $stops[$i]['name'];
                            $right = $p['x'] > $map['width'] * 0.6;
                            $first = ! in_array($name, $labelled, true);
                        @endphp
                        @if ($first)
                            @php $labelled[] = $name; $number++; @endphp
                            <g class="map__pin" style="--i: {{ $i }}" transform="translate({{ $p['x'] }} {{ $p['y'] }})">
                                <circle r="10"/>
                                <text class="map__num" y="4" text-anchor="middle">{{ $number }}</text>
                                <text x="{{ $right ? -15 : 15 }}" y="5" @if ($right) text-anchor="end" @endif>{{ $name }}</text>
                            </g>
                        @endif
                    @endforeach

                    {{-- Compass rose, bottom left, in the ocean. --}}
                    <g class="map__compass" transform="translate(64 {{ $map['height'] - 150 }})">
                        <circle r="34"/><circle r="26"/>
                        <path d="M0 -46L7 -7L46 0L7 7L0 46L-7 7L-46 0L-7 -7Z"/>
                        <path class="map__compass-n" d="M0 -46L7 -7L0 0L-7 -7Z"/>
                        <text y="-52" text-anchor="middle">N</text>
                    </g>

                    {{-- Scale bar, 200 km in two chequered halves. --}}
                    <g class="map__scale" transform="translate(34 {{ $map['height'] - 44 }})">
                        <rect width="{{ $map['km200'] / 2 }}" height="6"/>
                        <rect x="{{ $map['km200'] / 2 }}" width="{{ $map['km200'] / 2 }}" height="6" class="map__scale-alt"/>
                        <text y="-6">0</text><text x="{{ $map['km200'] }}" y="-6" text-anchor="middle">200 km</text>
                    </g>

                    @if (filled($data['title'] ?? null))
                        @php $cw = min(300, max(170, mb_strlen($data['title']) * 11)); @endphp
                        <g class="map__cartouche" transform="translate({{ $map['width'] - 26 - $cw }} {{ $map['height'] - 104 }})">
                            <rect width="{{ $cw }}" height="70" rx="3"/>
                            <rect x="5" y="5" width="{{ $cw - 10 }}" height="60" rx="2" class="map__cartouche-inner"/>
                            <text x="{{ $cw / 2 }}" y="32" text-anchor="middle">{{ $data['title'] }}</text>
                            <text x="{{ $cw / 2 }}" y="52" text-anchor="middle" class="map__small">{{ $site->brandName() }}</text>
                        </g>
                    @endif
                </svg>
            </figure>
            </div></div>
        </div>
    </section>
@endif
