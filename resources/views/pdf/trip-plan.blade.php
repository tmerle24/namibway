<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 2cm; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #2c2521; background: #fff; }

    .header { border-bottom: 2px solid #c0533a; padding-bottom: 12px; margin-bottom: 20px; }
    .header h1 { font-size: 20px; color: #2c2521; margin-bottom: 2px; }
    .header .meta { font-size: 10px; color: #7a6a5e; }
    .logo { font-size: 13px; font-weight: bold; color: #c0533a; letter-spacing: 0.5px; float: right; margin-top: 4px; }

    .summary { background: #faf8f5; border-left: 3px solid #c0533a; padding: 10px 14px; margin-bottom: 20px; font-size: 11px; color: #5b5346; }

    .variant { margin-bottom: 28px; page-break-inside: avoid; }
    .variant-title { font-size: 14px; font-weight: bold; color: #2c2521; border-bottom: 1px solid #e8e0d4; padding-bottom: 6px; margin-bottom: 12px; }

    .route-map { margin-bottom: 16px; }
    .route-map svg { width: 100%; height: auto; display: block; }

    .day-row { display: flex; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dotted #e8e0d4; }
    .day-col { width: 44px; flex-shrink: 0; padding-top: 1px; }
    .day-num { font-weight: bold; color: #c0533a; font-size: 13px; }
    .day-date { font-size: 8px; color: #a09080; margin-top: 1px; }
    .day-location { font-weight: bold; margin-bottom: 4px; font-size: 11.5px; }
    .day-items { font-size: 10.5px; color: #5b5346; }
    .day-item { margin-bottom: 2px; }
    .day-item span { color: #7a6a5e; }

    .vehicle-row { margin-bottom: 14px; font-size: 11px; color: #5b5346; }
    .vehicle-row strong { color: #2c2521; }

    .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #e8e0d4; font-size: 9px; color: #a09080; text-align: center; }
</style>
</head>
<body>

<div class="header">
    <span class="logo">NamibWay</span>
    <h1>{{ $title ?: 'Your Namibia Trip Plan' }}</h1>
    <div class="meta">Generated on {{ now()->format('d M Y') }} · namibway.com</div>
</div>

@if(!empty($plan['trip_summary']))
<div class="summary">{{ $plan['trip_summary'] }}</div>
@endif

@foreach($plan['variants'] as $variant)
<div class="variant">
    <div class="variant-title">{{ $variant['name'] }}</div>

    @php
        // Build ordered waypoints for this variant (deduplicated consecutive)
        $waypoints = [];
        $prevKey = '';
        foreach ($variant['days'] as $day) {
            $key = mb_strtolower(trim($day['location'] ?? ''));
            if ($key === '' || $key === $prevKey) continue;
            if (!isset($regionCoords[$key])) continue;
            $waypoints[] = [
                'label' => $day['location'],
                'day'   => $day['day'],
                'lat'   => $regionCoords[$key]['lat'],
                'lng'   => $regionCoords[$key]['lng'],
            ];
            $prevKey = $key;
        }

        // Normalize to SVG space (Namibia bounds)
        $svgW = 500; $svgH = 340;
        $latMin = -17; $latMax = -30;
        $lngMin = 11;  $lngMax = 26;
        $pad = 30;

        $points = array_map(function($wp) use ($svgW, $svgH, $latMin, $latMax, $lngMin, $lngMax, $pad) {
            $x = $pad + ($wp['lng'] - $lngMin) / ($lngMax - $lngMin) * ($svgW - 2 * $pad);
            $y = $pad + ($wp['lat'] - $latMin) / ($latMax - $latMin) * ($svgH - 2 * $pad);
            return array_merge($wp, ['x' => round($x, 1), 'y' => round($y, 1)]);
        }, $waypoints);
    @endphp

    @if(count($points) >= 2)
    <div class="route-map">
        <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" xmlns="http://www.w3.org/2000/svg">
            {{-- Dashed route line --}}
            <polyline
                points="{{ implode(' ', array_map(fn($p) => "{$p['x']},{$p['y']}", $points)) }}"
                fill="none"
                stroke="#c0533a"
                stroke-width="1.5"
                stroke-dasharray="5 4"
                opacity="0.8"
            />
            {{-- Numbered markers --}}
            @foreach($points as $i => $p)
            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="10" fill="#c0533a" stroke="#fff" stroke-width="1.5"/>
            <text x="{{ $p['x'] }}" y="{{ $p['y'] + 4 }}" text-anchor="middle" font-size="9" font-weight="bold" fill="#fff">{{ $i + 1 }}</text>
            <text x="{{ $p['x'] }}" y="{{ $p['y'] + 20 }}" text-anchor="middle" font-size="8" fill="#5b5346">{{ $p['label'] }}</text>
            @endforeach
        </svg>
    </div>
    @endif

    @if(!empty($variant['vehicle']))
    <div class="vehicle-row">
        <strong>Vehicle:</strong> {{ $variant['vehicle']['name'] ?? $variant['vehicle'] }}
    </div>
    @endif

    @foreach($variant['days'] as $day)
    <div class="day-row">
        <div class="day-col">
            <div class="day-num">{{ $day['day'] }}</div>
            @if(!empty($day['date']))<div class="day-date">{{ $day['date'] }}</div>@endif
        </div>
        <div>
            <div class="day-location">{{ $day['location'] }}</div>
            <div class="day-items">
                @if(!empty($day['accommodation']))
                <div class="day-item"><span>Stay: </span>{{ $day['accommodation']['name'] ?? $day['accommodation'] }}</div>
                @endif
                @if(!empty($day['activity']))
                <div class="day-item"><span>Activity: </span>{{ $day['activity']['name'] ?? $day['activity'] }}</div>
                @endif
                @if(!empty($day['restaurant']))
                <div class="day-item"><span>Dinner: </span>{{ $day['restaurant']['name'] ?? $day['restaurant'] }}</div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endforeach

<div class="footer">
    NamibWay · The smartest way to experience Namibia<br>
    View online: <strong>{{ $shareUrl }}</strong>
</div>

</body>
</html>
