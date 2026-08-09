<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    /* Top margin budgets room for a title that wraps to 2 lines — the header
       height + its negative top-offset below must stay in lockstep with this. */
    @page { margin: 4.3cm 2cm 2.3cm 2cm; }
    /* A universal `* { margin/padding: 0 }` reset breaks dompdf's position:fixed
       header/footer entirely (verified empirically) — reset the specific tags
       that actually carry default spacing instead. */
    * { box-sizing: border-box; }
    body, h1, p { margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #2c2521; background: #fff; }

    .pdf-header { position: fixed; top: -3.9cm; left: 0; right: 0; height: 3.6cm; border-bottom: 2px solid #c0533a; padding-bottom: 10px; }
    .pdf-header .logo-row { text-align: right; margin-bottom: 4px; }
    .pdf-header .logo-row img { height: 26px; }
    .pdf-header .eyebrow { font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; color: #c0533a; font-weight: bold; }
    .pdf-header h1 { font-size: 16px; line-height: 1.25; color: #2c2521; margin: 2px 0 2px; }
    .pdf-header .meta { font-size: 10px; color: #7a6a5e; }

    .pdf-footer { position: fixed; bottom: -2.05cm; left: 0; right: 0; height: 1.5cm; border-top: 1px solid #e8e0d4; padding-top: 8px; font-size: 9px; color: #a09080; text-align: center; }

    .summary { background: #faf8f5; border-left: 3px solid #c0533a; padding: 10px 14px; margin-bottom: 20px; font-size: 11px; color: #5b5346; }

    .variant { margin-bottom: 28px; page-break-inside: avoid; }
    .variant-title { font-size: 14px; font-weight: bold; color: #2c2521; border-bottom: 1px solid #e8e0d4; padding-bottom: 6px; margin-bottom: 12px; }

    .route-map { margin-bottom: 16px; text-align: center; }
    .route-map img { width: 100%; max-width: 460px; height: auto; }

    .day-row { display: flex; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dotted #e8e0d4; }
    .day-col { width: 70px; flex-shrink: 0; padding-top: 1px; }
    .day-num { font-weight: bold; color: #c0533a; font-size: 13px; }
    .day-date { font-size: 8px; color: #a09080; margin-top: 1px; }
    .day-location { font-weight: bold; margin-bottom: 4px; font-size: 11.5px; }
    .day-items { font-size: 10.5px; color: #5b5346; }
    .day-item { margin-bottom: 2px; }
    .day-item span { color: #7a6a5e; }

    .vehicle-row { margin-bottom: 14px; font-size: 11px; color: #5b5346; }
    .vehicle-row strong { color: #2c2521; }
</style>
</head>
<body>

<div class="pdf-header">
    <div class="logo-row"><img src="{{ $logoDataUri }}" alt="NamibWay"></div>
    <div class="eyebrow">Travel Itinerary</div>
    <h1>{{ $title ?: 'Your Namibia Trip Plan' }}</h1>
    <div class="meta">
        @if($dateRange) {{ $dateRange }} &middot; @endif
        Generated {{ now()->format('d M Y') }} &middot; namibway.com
    </div>
</div>

<div class="pdf-footer">
    NamibWay &middot; The smartest way to experience Namibia<br>
    View online: <strong>{{ $shareUrl }}</strong>
</div>

@if(!empty($plan['trip_summary']))
<div class="summary">{{ $plan['trip_summary'] }}</div>
@endif

@foreach($plan['variants'] as $variantIndex => $variant)
<div class="variant">
    <div class="variant-title">{{ $variant['name'] }}</div>

    @if(!empty($routeMaps[$variantIndex]))
    <div class="route-map">
        <img src="{{ $routeMaps[$variantIndex] }}" alt="Route map">
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
            @if(!empty($day['date']))
            <div class="day-date">{{ $day['date'] }}@if(!empty($day['date_to'])) &ndash; {{ $day['date_to'] }}@endif</div>
            @endif
        </div>
        <div>
            <div class="day-location">{{ $day['location'] }}</div>
            <div class="day-items">
                @if(!empty($day['accommodation']))
                <div class="day-item"><span>Stay: </span>{{ $day['accommodation']['name'] ?? $day['accommodation'] }}</div>
                @endif
                {{-- `activities`/`restaurants` (arrays) is the current shape — a
                     traveler can add a 2nd/3rd of each. Older saved plans, from
                     before that feature, still carry the singular
                     `activity`/`restaurant` field instead. --}}
                @php
                    $activities = $day['activities'] ?? (!empty($day['activity']) ? [$day['activity']] : []);
                    $restaurants = $day['restaurants'] ?? (!empty($day['restaurant']) ? [$day['restaurant']] : []);
                @endphp
                @foreach($activities as $activity)
                <div class="day-item"><span>Activity: </span>{{ $activity['name'] ?? $activity }}</div>
                @endforeach
                @foreach($restaurants as $restaurant)
                <div class="day-item"><span>Dinner: </span>{{ $restaurant['name'] ?? $restaurant }}</div>
                @endforeach
            </div>
        </div>
    </div>
    {{-- The checkout morning after a stage's last night — a `days` entry is a
         night, so what's planned for the departure day itself rides on the
         last night as `departure_activities`/`departure_restaurants`. --}}
    @php
        $departureActivities = $day['departure_activities'] ?? [];
        $departureRestaurants = $day['departure_restaurants'] ?? [];
    @endphp
    @if(!empty($departureActivities) || !empty($departureRestaurants))
    <div class="day-row">
        <div class="day-col">
            <div class="day-num">&rarr;</div>
            @if(!empty($day['date_to']))
            <div class="day-date">{{ $day['date_to'] }}</div>
            @endif
        </div>
        <div>
            <div class="day-location">{{ $day['location'] }} &middot; Departure day</div>
            <div class="day-items">
                @foreach($departureActivities as $activity)
                <div class="day-item"><span>Activity: </span>{{ $activity['name'] ?? $activity }}</div>
                @endforeach
                @foreach($departureRestaurants as $restaurant)
                <div class="day-item"><span>Dinner: </span>{{ $restaurant['name'] ?? $restaurant }}</div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
    @endforeach
</div>
@endforeach

</body>
</html>
