@php
    /**
     * "A map that opens straight into Google Maps" — the flyer's words, and
     * exactly what this is. No embedded map, no tiles, no third-party script on
     * a page whose whole promise is that it opens on a weak connection. The
     * visitor's next tap is their own maps app anyway, and that starts
     * navigation from where they are standing, which an embed cannot.
     */
    $coords = filled($site->latitude) && filled($site->longitude);
    $query = $coords ? $site->latitude.','.$site->longitude : (string) $site->address;
    $mapsUrl = filled($query) ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($query) : null;
@endphp
<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="split reveal">
            <div>
                @if (filled($data['heading'] ?? null))
                    <h2>{{ $data['heading'] }}</h2>
                @endif

                @if (filled($site->address))
                    <p class="prose" style="white-space: pre-line">{{ $site->address }}</p>
                @endif

                @if ($mapsUrl)
                    <p style="margin-top: var(--s4)">
                        <a class="btn btn--ghost" href="{{ $mapsUrl }}" target="_blank" rel="noopener">Open in Google Maps</a>
                    </p>
                @endif
            </div>

            @if (filled($data['directions_note'] ?? null))
                <div class="prose">{{ $data['directions_note'] }}</div>
            @endif
        </div>
    </div>
</section>
