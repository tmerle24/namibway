@php
    /** @var \App\Models\Site $site */
    /** @var string $accent */
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Order received — {{ $site->name }}</title>
    <meta name="robots" content="noindex, nofollow">
    @include('sites.partials.styles')
    <style>
        .thanks-wrap {
            max-width: 420px;
            margin: 0 auto;
            padding: var(--s7) var(--s4) var(--s6);
            text-align: center;
        }
        .thanks-icon { font-size: 64px; margin-bottom: var(--s4); line-height: 1; }
        .thanks-title {
            font-family: var(--font-display);
            font-size: 30px;
            font-weight: 600;
            margin: 0 0 var(--s3);
        }
        .thanks-body { color: var(--slate); font-size: 16px; line-height: 1.7; margin: 0 0 var(--s5); }
        .thanks-seller { font-weight: 600; color: var(--ink); }
        .thanks-back {
            display: inline-block;
            padding: 12px var(--s5);
            background: var(--ink);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: opacity .15s;
        }
        .thanks-back:hover { opacity: .85; }
    </style>
</head>
<body style="background: var(--salt);">
    <div class="thanks-wrap">
        <div class="thanks-icon">✅</div>
        <h1 class="thanks-title">Order received!</h1>
        <p class="thanks-body">
            Your order has been sent to <span class="thanks-seller">{{ $site->name }}</span>.<br>
            They'll come back to you by email to confirm availability.
        </p>
        <a href="{{ $site->pageUrl() }}" class="thanks-back">Back to {{ $site->name }}</a>
    </div>
</body>
</html>
