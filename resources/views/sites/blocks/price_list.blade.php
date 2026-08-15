@php
    $waDigits = ($data['whatsapp_order'] ?? false)
        ? preg_replace('/\D+/', '', (string) $site->whatsapp)
        : null;
    $waOrder = $waDigits !== null && strlen($waDigits) >= 8;
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            <div class="rows" @if ($waOrder) id="order-rows" @endif>
                @foreach ($data['items'] ?? [] as $item)
                    <div class="row"
                        @if ($waOrder)
                            data-name="{{ $item['name'] }}"
                            data-price="{{ $item['price'] ?? '' }}"
                        @endif
                    >
                        @if ($waOrder)
                            <span class="row__sel">
                                <input type="checkbox" class="order-check" aria-label="{{ $item['name'] }}">
                                <input type="number" class="order-qty" value="1" min="1" max="99" aria-label="Quantity">
                            </span>
                        @endif
                        <span class="row__main">
                            <span class="row__name">{{ $item['name'] }}</span>
                            @if (filled($item['description'] ?? null))
                                <span class="row__note">{{ $item['description'] }}</span>
                            @endif
                        </span>
                        @if (filled($item['price'] ?? null))
                            <span class="row__value">{{ $item['price'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($waOrder)
                <div class="order-bar" id="order-bar" hidden>
                    <span class="order-bar__count" id="order-count"></span>
                    <a class="btn order-bar__btn" id="order-wa" href="#" target="_blank" rel="noopener">Order via WhatsApp</a>
                </div>
                <script>
                (function () {
                    var rows = document.getElementById('order-rows');
                    var bar = document.getElementById('order-bar');
                    var countEl = document.getElementById('order-count');
                    var waLink = document.getElementById('order-wa');
                    var waBase = 'https://wa.me/' + {{ json_encode($waDigits) }};
                    var siteName = {{ json_encode($site->name) }};

                    function update() {
                        var checks = rows.querySelectorAll('.order-check:checked');
                        if (checks.length === 0) { bar.hidden = true; return; }
                        bar.hidden = false;
                        var n = checks.length;
                        countEl.textContent = n + (n === 1 ? ' item' : ' items') + ' selected';
                        var msg = 'Hello ' + siteName + ', I would like to order:\n\n';
                        checks.forEach(function (check) {
                            var row = check.closest('.row');
                            var qty = parseInt(row.querySelector('.order-qty').value, 10) || 1;
                            msg += '• ' + (qty > 1 ? qty + '× ' : '') + row.dataset.name;
                            if (row.dataset.price) msg += ' (' + row.dataset.price + ')';
                            msg += '\n';
                        });
                        waLink.href = waBase + '?text=' + encodeURIComponent(msg);
                    }

                    rows.addEventListener('change', update);
                    rows.addEventListener('input', update);
                }());
                </script>
            @endif

            @if (filled($data['note'] ?? null))
                <p class="note">{{ $data['note'] }}</p>
            @endif
        </div>
    </div>
</section>
