@php
    /** @var \App\Models\Site $site */
    /** @var \Illuminate\Support\Collection<int, \App\Models\ShopProduct> $products */
    /** @var string $accent */
    /** @var string $orderAction */

    $hasError = request()->query('error') === '1';
@endphp
<!DOCTYPE html>
<html lang="{{ $site->default_locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Order — {{ $site->name }}</title>
    <meta name="robots" content="noindex, nofollow">
    @include('sites.partials.styles')
    <style>
        /* ---- Order-form extras ---------------------------------------- */
        .order-header {
            background: var(--ink);
            color: #fff;
            padding: var(--s4) var(--s4);
            display: flex;
            align-items: center;
            gap: var(--s3);
        }
        .order-header__name {
            font-family: var(--font-brand);
            font-weight: 700;
            font-size: clamp(18px, 4vw, 24px);
            letter-spacing: -.01em;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .order-header__badge {
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
            opacity: .55;
            white-space: nowrap;
        }

        .order-main { max-width: 640px; margin: 0 auto; padding: var(--s5) var(--s4) 120px; }

        .order-section-title {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 var(--s4);
            color: var(--ink);
        }

        /* ---- Product rows -------------------------------------------- */
        .product-rows { list-style: none; margin: 0 0 var(--s5); padding: 0; display: flex; flex-direction: column; gap: var(--s3); }

        .product-row {
            display: flex;
            align-items: center;
            gap: var(--s3);
            background: #fff;
            border-radius: 10px;
            padding: var(--s3);
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
        }
        .product-row__img {
            width: 72px;
            height: 72px;
            flex-shrink: 0;
            border-radius: 7px;
            overflow: hidden;
            background: var(--bone);
        }
        .product-row__img img { width: 100%; height: 100%; object-fit: cover; }
        .product-row__body { flex: 1; min-width: 0; }
        .product-row__name { font-weight: 600; font-size: 15px; line-height: 1.3; margin: 0 0 2px; }
        .product-row__category { font-size: 12px; letter-spacing: .05em; text-transform: uppercase; color: var(--slate); }
        .product-row__price { font-size: 14px; color: var(--slate); margin-top: 2px; }

        /* ---- Stepper -------------------------------------------------- */
        .stepper {
            display: flex;
            align-items: center;
            gap: var(--s2);
            flex-shrink: 0;
        }
        .stepper__btn {
            width: 34px; height: 34px;
            border-radius: 50%;
            border: 1.5px solid var(--bone);
            background: #fff;
            color: var(--ink);
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background .15s, border-color .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .stepper__btn:active { background: var(--salt); }
        .stepper__btn[data-action="inc"] { border-color: var(--accent); color: var(--accent); }
        .stepper__val {
            min-width: 22px;
            text-align: center;
            font-variant-numeric: tabular-nums;
            font-size: 16px;
            font-weight: 600;
        }

        /* ---- Running total bar --------------------------------------- */
        .order-total-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: var(--ink);
            color: #fff;
            padding: var(--s3) var(--s4);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: var(--s3);
            z-index: 10;
            box-shadow: 0 -2px 12px rgba(0,0,0,.18);
        }
        .order-total-bar__label { font-size: 14px; opacity: .7; }
        .order-total-bar__amount { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .order-total-bar__hidden { display: none; }

        /* ---- Contact + payment --------------------------------------- */
        .order-card {
            background: #fff;
            border-radius: 12px;
            padding: var(--s4);
            margin-bottom: var(--s4);
            box-shadow: 0 1px 3px rgba(0,0,0,.07);
        }

        .field { margin-bottom: var(--s4); }
        .field label { display: block; font-size: 13px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--slate); margin-bottom: var(--s2); }
        .field input, .field textarea {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid var(--bone);
            border-radius: 8px;
            font-size: 16px;
            font-family: inherit;
            background: var(--salt);
            color: var(--ink);
            transition: border-color .15s;
            -webkit-appearance: none;
        }
        .field input:focus, .field textarea:focus { outline: none; border-color: var(--accent); background: #fff; }

        /* ---- Payment choice ----------------------------------------- */
        .payment-options { display: flex; flex-direction: column; gap: var(--s2); margin-bottom: var(--s4); }
        .payment-option {
            display: flex;
            align-items: flex-start;
            gap: var(--s3);
            padding: var(--s3) var(--s4);
            border: 1.5px solid var(--bone);
            border-radius: 10px;
            cursor: pointer;
            transition: border-color .15s;
        }
        .payment-option:has(input:checked) { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 6%, white); }
        .payment-option input[type="radio"] { margin-top: 3px; accent-color: var(--accent); width: 18px; height: 18px; flex-shrink: 0; cursor: pointer; }
        .payment-option__label { font-weight: 600; font-size: 15px; }
        .payment-option__desc { font-size: 13px; color: var(--slate); margin-top: 2px; }

        .btn-order {
            display: block;
            width: 100%;
            padding: 16px var(--s4);
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 17px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: filter .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-order:active { filter: brightness(.9); }
        .btn-order:disabled { opacity: .5; cursor: not-allowed; }

        .order-error {
            background: #FEE2E2;
            border: 1.5px solid #FCA5A5;
            border-radius: 8px;
            color: #B91C1C;
            padding: var(--s3) var(--s4);
            margin-bottom: var(--s4);
            font-size: 14px;
        }

        .order-disclaimer {
            text-align: center;
            font-size: 12px;
            color: var(--slate);
            margin-top: var(--s4);
        }
        .order-disclaimer a { color: var(--slate); text-decoration: underline; }
    </style>
</head>
<body>
    <header class="order-header">
        <span class="order-header__name">{{ $site->name }}</span>
        <span class="order-header__badge">Order</span>
    </header>

    <main class="order-main">

        @if ($hasError)
            <p class="order-error">Something was missing from your order — please check the form and try again.</p>
        @endif

        <form method="POST" action="{{ $orderAction }}" id="order-form">
            {{-- Honeypot --}}
            <div style="display:none" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            {{-- Product list --}}
            <p class="order-section-title">Choose items</p>
            <ul class="product-rows" id="product-list">
                @foreach ($products as $product)
                    @php $thumb = $product->images->first(); @endphp
                    <li class="product-row">
                        <div class="product-row__img">
                            @if ($thumb)
                                <img src="{{ $thumb->thumb(160) }}" alt="{{ $product->title }}" loading="lazy">
                            @else
                                @include('sites.partials.product-placeholder')
                            @endif
                        </div>
                        <div class="product-row__body">
                            @if (filled($product->category))
                                <div class="product-row__category">{{ $product->category }}</div>
                            @endif
                            <p class="product-row__name">{{ $product->title }}</p>
                            @if (filled($product->priceLabel()))
                                <p class="product-row__price">{{ $product->priceLabel() }}</p>
                            @endif
                        </div>
                        <div class="stepper" data-product-id="{{ $product->id }}" data-price="{{ $product->price ?? 0 }}" data-currency="{{ $product->currency }}">
                            <button type="button" class="stepper__btn" data-action="dec" aria-label="Remove one {{ $product->title }}">−</button>
                            <span class="stepper__val" aria-live="polite">0</span>
                            <button type="button" class="stepper__btn" data-action="inc" aria-label="Add one {{ $product->title }}">+</button>
                            <input type="hidden" name="items[{{ $product->id }}]" value="0" class="qty-input">
                        </div>
                    </li>
                @endforeach
            </ul>

            {{-- Contact --}}
            <div class="order-card">
                <p class="order-section-title" style="margin-bottom: var(--s3)">Your details</p>
                <div class="field">
                    <label for="ord-name">Full name</label>
                    <input type="text" id="ord-name" name="name" required maxlength="255" autocomplete="name">
                </div>
                <div class="field">
                    <label for="ord-email">Email</label>
                    <input type="email" id="ord-email" name="email" required maxlength="255" autocomplete="email">
                </div>
                <div class="field">
                    <label for="ord-phone">Phone (optional)</label>
                    <input type="tel" id="ord-phone" name="phone" maxlength="50" autocomplete="tel">
                </div>
                <div class="field" style="margin-bottom: 0">
                    <label for="ord-message">Note (optional)</label>
                    <textarea id="ord-message" name="message" rows="2" maxlength="2000" placeholder="Special requests, collection time…"></textarea>
                </div>
            </div>

            {{-- Payment choice --}}
            <div class="order-card">
                <p class="order-section-title" style="margin-bottom: var(--s3)">Payment</p>
                <div class="payment-options">
                    <label class="payment-option">
                        <input type="radio" name="payment" value="cash" required checked>
                        <div>
                            <div class="payment-option__label">💵 Cash on collection</div>
                            <div class="payment-option__desc">Pay when you collect your order from {{ $site->name }}.</div>
                        </div>
                    </label>
                    <label class="payment-option">
                        <input type="radio" name="payment" value="simulated" required>
                        <div>
                            <div class="payment-option__label">💳 Pay now (prototype demo)</div>
                            <div class="payment-option__desc">Simulated payment — no real card is charged. For testing only.</div>
                        </div>
                    </label>
                </div>

                <button type="submit" class="btn-order" id="submit-btn" disabled>
                    Place order
                </button>
                <p class="order-disclaimer">
                    By placing this order you agree to the
                    <a href="{{ $site->pageUrl('privacy') }}" target="_blank" rel="noopener">privacy policy</a>.
                    {{ $site->name }} will confirm availability by email.
                </p>
            </div>
        </form>

    </main>

    {{-- Sticky total bar (hidden until at least one item is selected) --}}
    <div class="order-total-bar order-total-bar__hidden" id="total-bar" aria-live="polite" aria-atomic="true">
        <span class="order-total-bar__label">Subtotal</span>
        <span class="order-total-bar__amount" id="total-amount">NAD 0.00</span>
    </div>

    <script>
    (function () {
        var totalBar    = document.getElementById('total-bar');
        var totalAmount = document.getElementById('total-amount');
        var submitBtn   = document.getElementById('submit-btn');
        var steppers    = document.querySelectorAll('.stepper');
        var runningTotal = 0;
        var currency     = 'NAD';

        function fmt(amount, cur) {
            return cur + ' ' + amount.toFixed(2);
        }

        function recalc() {
            runningTotal = 0;
            var hasItems = false;

            steppers.forEach(function (s) {
                var qty   = parseInt(s.querySelector('.stepper__val').textContent, 10) || 0;
                var price = parseFloat(s.dataset.price) || 0;
                currency  = s.dataset.currency || 'NAD';
                runningTotal += qty * price;
                if (qty > 0) hasItems = true;
            });

            if (hasItems) {
                totalBar.classList.remove('order-total-bar__hidden');
                totalAmount.textContent = fmt(runningTotal, currency);
                submitBtn.disabled = false;
            } else {
                totalBar.classList.add('order-total-bar__hidden');
                submitBtn.disabled = true;
            }
        }

        steppers.forEach(function (stepper) {
            var valEl  = stepper.querySelector('.stepper__val');
            var qtyEl  = stepper.querySelector('.qty-input');
            var decBtn = stepper.querySelector('[data-action="dec"]');
            var incBtn = stepper.querySelector('[data-action="inc"]');

            decBtn.addEventListener('click', function () {
                var v = parseInt(valEl.textContent, 10) || 0;
                if (v > 0) {
                    v--;
                    valEl.textContent = v;
                    qtyEl.value = v;
                    recalc();
                }
            });

            incBtn.addEventListener('click', function () {
                var v = parseInt(valEl.textContent, 10) || 0;
                if (v < 99) {
                    v++;
                    valEl.textContent = v;
                    qtyEl.value = v;
                    recalc();
                }
            });
        });
    }());
    </script>
</body>
</html>
