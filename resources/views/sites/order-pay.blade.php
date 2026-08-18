@php
    /** @var \App\Models\Site $site */
    /** @var \App\Models\Inquiry $inquiry */
    /** @var string $accent */
    /** @var string $confirmAction */
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Payment — {{ $site->name }}</title>
    <meta name="robots" content="noindex, nofollow">
    @include('sites.partials.styles')
    <style>
        .pay-wrap { max-width: 480px; margin: 0 auto; padding: var(--s5) var(--s4) var(--s7); }

        .pay-header {
            text-align: center;
            margin-bottom: var(--s5);
        }
        .pay-header__title {
            font-family: var(--font-display);
            font-size: 26px;
            font-weight: 600;
            margin: 0 0 var(--s2);
        }
        .pay-header__sub { color: var(--slate); font-size: 15px; }

        .pay-card {
            background: #fff;
            border-radius: 14px;
            padding: var(--s4);
            margin-bottom: var(--s4);
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
        }
        .pay-card__title {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--slate);
            margin: 0 0 var(--s3);
        }

        .order-lines { list-style: none; margin: 0; padding: 0; }
        .order-line { display: flex; justify-content: space-between; padding: var(--s2) 0; border-bottom: 1px solid var(--bone); font-size: 15px; }
        .order-line:last-child { border-bottom: none; }
        .order-line__name { color: var(--ink); }
        .order-line__qty { color: var(--slate); margin: 0 var(--s2); }
        .order-line__price { font-variant-numeric: tabular-nums; font-weight: 500; }
        .order-total { display: flex; justify-content: space-between; margin-top: var(--s3); font-weight: 700; font-size: 18px; }

        .demo-badge {
            display: inline-block;
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid #FCD34D;
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: var(--s3);
        }

        .pay-terminal {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 14px;
            padding: var(--s5) var(--s4);
            text-align: center;
            color: #fff;
            margin-bottom: var(--s4);
        }
        .pay-terminal__icon { font-size: 48px; margin-bottom: var(--s3); line-height: 1; }
        .pay-terminal__label { font-size: 14px; opacity: .6; letter-spacing: .05em; text-transform: uppercase; margin-bottom: var(--s2); }
        .pay-terminal__amount { font-size: 38px; font-weight: 700; font-variant-numeric: tabular-nums; margin-bottom: var(--s4); }
        .pay-terminal__note { font-size: 12px; opacity: .45; margin-top: var(--s3); }

        .btn-pay {
            display: block;
            width: 100%;
            padding: 18px var(--s4);
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            transition: filter .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-pay:active { filter: brightness(.9); }

        .pay-disclaimer {
            text-align: center;
            font-size: 12px;
            color: var(--slate);
            margin-top: var(--s4);
            line-height: 1.6;
        }

        .pay-seller { text-align: center; font-size: 14px; color: var(--slate); margin-bottom: var(--s5); }
        .pay-seller strong { color: var(--ink); }
    </style>
</head>
<body style="background: var(--salt);">

    <div class="pay-wrap">

        <div class="pay-header">
            <p class="pay-header__title">{{ $site->name }}</p>
            <p class="pay-header__sub">Review your order and confirm payment</p>
        </div>

        {{-- Order summary --}}
        <div class="pay-card">
            <p class="pay-card__title">Order summary</p>
            <ul class="order-lines">
                @foreach ($inquiry->items as $item)
                    <li class="order-line">
                        <span class="order-line__name">{{ $item->name }}<span class="order-line__qty">× {{ $item->quantity }}</span></span>
                        <span class="order-line__price">{{ number_format($item->line_total, 2) }} {{ $item->currency }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($inquiry->total_amount)
                <div class="order-total">
                    <span>Total</span>
                    <span>{{ number_format($inquiry->total_amount, 2) }} {{ $inquiry->currency ?? 'NAD' }}</span>
                </div>
            @endif
        </div>

        {{-- Demo payment terminal --}}
        <span class="demo-badge">Demo — no real payment</span>
        <div class="pay-terminal">
            <div class="pay-terminal__icon">💳</div>
            <div class="pay-terminal__label">Amount to pay</div>
            <div class="pay-terminal__amount">
                {{ $inquiry->currency ?? 'NAD' }} {{ number_format($inquiry->total_amount ?? 0, 2) }}
            </div>
            <div class="pay-terminal__note">This is a prototype simulation. No card details are required and no real transaction occurs.</div>
        </div>

        <form method="POST" action="{{ $confirmAction }}">
            <button type="submit" class="btn-pay">
                Confirm payment (demo)
            </button>
        </form>

        <p class="pay-disclaimer">
            NamibWay does not process or hold your payment.<br>
            In production, this would be a link to {{ $site->name }}'s own payment page.
        </p>

    </div>

</body>
</html>
