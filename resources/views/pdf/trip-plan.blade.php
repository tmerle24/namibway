<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #2c2521; background: #fff; }

    .header { border-bottom: 2px solid #c0533a; padding-bottom: 12px; margin-bottom: 20px; }
    .header h1 { font-size: 20px; color: #2c2521; margin-bottom: 2px; }
    .header .meta { font-size: 10px; color: #7a6a5e; }
    .logo { font-size: 13px; font-weight: bold; color: #c0533a; letter-spacing: 0.5px; float: right; margin-top: 4px; }

    .summary { background: #faf8f5; border-left: 3px solid #c0533a; padding: 10px 14px; margin-bottom: 20px; font-size: 11px; color: #5b5346; }

    .variant { margin-bottom: 28px; page-break-inside: avoid; }
    .variant-title { font-size: 14px; font-weight: bold; color: #2c2521; border-bottom: 1px solid #e8e0d4; padding-bottom: 6px; margin-bottom: 12px; }

    .day-row { display: flex; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dotted #e8e0d4; }
    .day-num { width: 36px; font-weight: bold; color: #c0533a; font-size: 13px; flex-shrink: 0; padding-top: 1px; }
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

    @if(!empty($variant['vehicle']))
    <div class="vehicle-row">
        <strong>Vehicle:</strong> {{ $variant['vehicle']['name'] }}
    </div>
    @endif

    @foreach($variant['days'] as $day)
    <div class="day-row">
        <div class="day-num">{{ $day['day'] }}</div>
        <div>
            <div class="day-location">{{ $day['location'] }}</div>
            <div class="day-items">
                @if(!empty($day['accommodation']))
                <div class="day-item"><span>Stay: </span>{{ $day['accommodation']['name'] }}</div>
                @endif
                @if(!empty($day['activity']))
                <div class="day-item"><span>Activity: </span>{{ $day['activity']['name'] }}</div>
                @endif
                @if(!empty($day['restaurant']))
                <div class="day-item"><span>Dinner: </span>{{ $day['restaurant']['name'] }}</div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endforeach

<div class="footer">
    NamibWay · The smartest way to experience Namibia · namibway.com
</div>

</body>
</html>
