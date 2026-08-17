@php
    use App\Sites\Blocks\EnquiryBlock;
    use App\Sites\Blocks\EnquiryFormType;
    use App\Sites\Rendering\SafeLink;

    // Resolved by the controller and passed in — one answer per request, shared
    // with the menu bar and the buttons, so a page cannot label one thing two
    // ways. The fallback is for a block rendered outside that path.
    $type = $enquiryType ?? EnquiryBlock::formTypeFor($site, $data);
    $channel = $data['channel'] ?? EnquiryBlock::CHANNEL_EMAIL;
    $today = \Carbon\CarbonImmutable::today()->toDateString();
    $sent = request()->query('sent') === '1';
    $failed = request()->query('sent') === '0';

    // One channel, never both. A WhatsApp form with no number to send to is not
    // a form, so it falls back to email rather than rendering a dead button.
    $waPhone = SafeLink::whatsapp($site->whatsapp);
    $viaWhatsApp = $channel === EnquiryBlock::CHANNEL_WHATSAPP && $waPhone !== null;

    $items = $enquiryItems ?? null;
    $showItems = $type->hasItems() && $items !== null && ! $items->isEmpty();

    $pickerTitle = match ($type) {
        EnquiryFormType::RestaurantOrder => 'Choose food',
        EnquiryFormType::ProductOrder    => 'Choose products',
        default                          => 'Choose items',
    };
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="split reveal">
            <div>
                <h2>{{ EnquiryBlock::heading($type, $data) }}</h2>

                <p class="prose">
                    {{ $data['intro'] ?? 'Send your details straight to '.$site->name.' — no payment, no obligation.' }}
                </p>
            </div>

            <div class="enquiry">
                @if ($sent)
                    <p class="enquiry__sent"><strong>Thank you — your request is on its way.</strong></p>
                    <p class="note">{{ $site->name }} will come back to you by email.</p>
                @else
                    @if ($failed)
                        <p class="enquiry__failed">Something in the form was incomplete — please check your details and send it again.</p>
                    @endif

                    {{-- A plain POST, no session and no CSRF token. The page
                         carries no cookie at all, which is most of why it is
                         small, and a token would mean issuing a session to
                         every reader of a public marketing page. What guards it
                         instead: a rate limit on the route, and a honeypot no
                         human ever fills in. There is nothing authenticated
                         here to forge.

                         With the WhatsApp channel the same fields are collected
                         and no POST happens at all — the button assembles them
                         into a message. Nothing is stored in that case, which
                         is the trade the owner makes when they choose it. --}}
                    <form method="post" action="{{ $enquiryAction }}" class="enquiry__form" id="eq-form"
                          @if ($viaWhatsApp) onsubmit="return false;" @endif>
                        <input type="hidden" name="form_type" value="{{ $type->value }}">

                        <div class="field">
                            <label for="eq-name">Full name</label>
                            <input type="text" id="eq-name" name="name" required maxlength="255">
                        </div>

                        @unless ($viaWhatsApp)
                        <div class="field">
                            <label for="eq-email">Email</label>
                            <input type="email" id="eq-email" name="email" required maxlength="255">
                        </div>
                        @endunless

                        <div class="field">
                            <label for="eq-phone">Phone (optional)</label>
                            <input type="tel" id="eq-phone" name="phone" maxlength="50">
                        </div>

                        @if ($type === EnquiryFormType::StayRequest)
                            {{-- Two real fields, one row. The pair reads as a
                                 single control the way the listing search does;
                                 what is stored stays two dates, because the
                                 confirmation mails and the calendar are built
                                 on them and free text would lose both. --}}
                            <div class="field">
                                <label for="eq-in">When</label>
                                <div class="enquiry__range">
                                    <input type="date" id="eq-in" name="check_in" min="{{ $today }}" required aria-label="Arrival">
                                    <span class="enquiry__range-dash" aria-hidden="true">–</span>
                                    <input type="date" id="eq-out" name="check_out" min="{{ $today }}" required aria-label="Departure">
                                </div>
                            </div>

                            <div class="enquiry__row">
                                <div class="field">
                                    <label for="eq-adults">Adults</label>
                                    <input type="number" id="eq-adults" name="adults" min="1" max="20" value="2">
                                </div>
                                <div class="field">
                                    <label for="eq-children">Children (under 12)</label>
                                    <input type="number" id="eq-children" name="children" min="0" max="20" value="0">
                                </div>
                            </div>
                        @elseif ($type === EnquiryFormType::TableReservation)
                            <div class="enquiry__row">
                                <div class="field">
                                    <label for="eq-in">Date</label>
                                    <input type="date" id="eq-in" name="check_in" min="{{ $today }}" required>
                                </div>
                                <div class="field">
                                    <label for="eq-time">Time</label>
                                    <input type="time" id="eq-time" name="time" required value="19:00">
                                </div>
                            </div>

                            <div class="field">
                                <label for="eq-adults">People</label>
                                <input type="number" id="eq-adults" name="adults" min="1" max="20" value="2" required>
                            </div>
                        @elseif ($showItems)
                            {{-- Hidden inputs carry the quantities to the backend and to the
                                 WhatsApp script. The picker modal controls these; they are
                                 never shown or filled in by hand. --}}
                            <div id="eq-inputs" data-currency="{{ $items->currency }}" style="display:none" aria-hidden="true">
                                @foreach ($items->sections() as $section)
                                    @foreach ($section['items'] as $item)
                                        <input type="hidden"
                                               class="enquiry__item-qty"
                                               name="items[{{ $item->id }}]"
                                               data-price="{{ $item->price }}"
                                               data-name="{{ $item->name }}"
                                               value="0">
                                    @endforeach
                                @endforeach
                            </div>

                            {{-- Trigger + inline summary --}}
                            <div class="eq-picker" id="eq-picker">
                                <button type="button" class="eq-picker__trigger" id="eq-picker-btn" aria-haspopup="dialog">
                                    <svg class="eq-picker__trigger-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                                    </svg>
                                    <span class="eq-picker__trigger-label">{{ $pickerTitle }}</span>
                                    <span class="eq-picker__badge" id="eq-badge" hidden>0</span>
                                </button>

                                <div class="eq-picker__summary" id="eq-summary" hidden>
                                    <ul class="eq-picker__lines" id="eq-summary-lines"></ul>
                                    <p class="eq-picker__subtotal">
                                        Total <strong id="eq-total">{{ $items->currency }} 0.00</strong>
                                        <button type="button" class="eq-picker__edit" id="eq-edit-btn">Edit order</button>
                                    </p>
                                </div>
                            </div>

                            @if ($type->needsAddress())
                                <div class="field">
                                    <label for="eq-address">Delivery address</label>
                                    <textarea id="eq-address" name="address" rows="3" maxlength="500" required></textarea>
                                </div>
                            @endif

                            {{-- Item picker drawer — sits inside the form so type="button"
                                 buttons cannot accidentally submit it. Position:fixed so it
                                 escapes the form's stacking context and covers the page. --}}
                            <div class="eq-drawer" id="eq-drawer" role="dialog" aria-modal="true"
                                 aria-labelledby="eq-drawer-title" hidden>
                                <div class="eq-drawer__backdrop" id="eq-backdrop"></div>
                                <div class="eq-drawer__panel" id="eq-panel">
                                    <div class="eq-drawer__head">
                                        <h3 class="eq-drawer__title" id="eq-drawer-title">{{ $pickerTitle }}</h3>
                                        <button type="button" class="eq-drawer__close" id="eq-drawer-close" aria-label="Close">
                                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="1" y1="1" x2="13" y2="13"/><line x1="13" y1="1" x2="1" y2="13"/></svg>
                                        </button>
                                    </div>

                                    <div class="eq-drawer__body" id="eq-drawer-body">
                                        @foreach ($items->sections() as $section)
                                            <div class="eq-section">
                                                <h4 class="eq-section__head">{{ $section['section'] }}</h4>

                                                @foreach ($section['items'] as $item)
                                                    <div class="eq-row" data-id="{{ $item->id }}" data-price="{{ $item->price }}" data-name="{{ e($item->name) }}">
                                                        @if ($item->imageUrl)
                                                            <img src="{{ $item->imageUrl }}" alt="{{ $item->name }}"
                                                                 class="eq-thumb" width="56" height="56" loading="lazy"
                                                                 data-full="{{ $item->imageUrl }}"
                                                                 title="Tap to enlarge">
                                                        @endif
                                                        <div class="eq-row__text">
                                                            <strong class="eq-row__name">{{ $item->name }}</strong>
                                                            @if (filled($item->description))
                                                                <span class="eq-row__desc">{{ \Illuminate\Support\Str::limit($item->description, 100) }}</span>
                                                            @endif
                                                            <span class="eq-row__price">{{ $item->priceLabel() }}</span>
                                                        </div>
                                                        <div class="eq-stepper">
                                                            <button type="button" class="eq-stepper__btn" data-action="dec" aria-label="Remove one {{ $item->name }}">−</button>
                                                            <span class="eq-stepper__val">0</span>
                                                            <button type="button" class="eq-stepper__btn" data-action="inc" aria-label="Add one {{ $item->name }}">+</button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="eq-drawer__foot">
                                        <div class="eq-drawer__total">
                                            <span>Total</span>
                                            <strong id="eq-drawer-total">{{ $items->currency }} 0.00</strong>
                                        </div>
                                        <button type="button" class="btn eq-drawer__confirm" id="eq-drawer-confirm">
                                            Confirm selection
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Lightbox overlay for thumbnail zoom — lives outside the drawer so it
                                 isn't clipped by the panel's overflow:hidden. --}}
                            <div class="eq-lightbox" id="eq-lightbox" hidden aria-hidden="true" role="dialog" aria-label="Product image">
                                <img class="eq-lightbox__img" id="eq-lightbox-img" src="" alt="">
                            </div>
                        @endif

                        <div class="field">
                            <label for="eq-message">Message (optional)</label>
                            <textarea id="eq-message" name="message" rows="4" maxlength="2000"></textarea>
                        </div>

                        {{-- Off-screen rather than display:none — some bots skip
                             hidden fields and fill everything else. --}}
                        <div class="enquiry__trap" aria-hidden="true">
                            <label for="eq-website">Leave this empty</label>
                            <input type="text" id="eq-website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        @if ($viaWhatsApp)
                            <a class="btn enquiry__submit" id="eq-wa-btn" href="{{ $waPhone }}" target="_blank" rel="noopener">Send via WhatsApp</a>
                        @else
                            <button class="btn enquiry__submit" type="submit">{{ $type->buttonLabel() === 'Contact' ? 'Send message' : 'Send request' }}</button>
                        @endif
                    </form>

                    @if ($showItems)
                        <script>
                        (function () {
                            var inputs  = document.getElementById('eq-inputs');
                            if (!inputs) return;

                            var currency   = inputs.getAttribute('data-currency') || '';
                            var drawer     = document.getElementById('eq-drawer');
                            var panel      = document.getElementById('eq-panel');
                            var backdrop   = document.getElementById('eq-backdrop');
                            var pickerBtn  = document.getElementById('eq-picker-btn');
                            var editBtn    = document.getElementById('eq-edit-btn');
                            var closeBtn   = document.getElementById('eq-drawer-close');
                            var confirmBtn = document.getElementById('eq-drawer-confirm');
                            var badge      = document.getElementById('eq-badge');
                            var summary    = document.getElementById('eq-summary');
                            var summaryLines = document.getElementById('eq-summary-lines');
                            var totalOut   = document.getElementById('eq-total');
                            var drawerTotal = document.getElementById('eq-drawer-total');

                            // Map id → { input, row, valEl }
                            var items = {};
                            inputs.querySelectorAll('.enquiry__item-qty').forEach(function (inp) {
                                var id = inp.name.replace(/^items\[/, '').replace(/\]$/, '');
                                var row = drawer.querySelector('.eq-row[data-id="' + id + '"]');
                                var valEl = row ? row.querySelector('.eq-stepper__val') : null;
                                items[id] = { input: inp, row: row, valEl: valEl };
                            });

                            // ---- Helpers ----

                            function fmt(n) { return currency + ' ' + n.toFixed(2); }

                            function qty(id) { return parseInt(items[id].input.value || '0', 10); }

                            function setQty(id, n) {
                                n = Math.max(0, Math.min(99, n));
                                items[id].input.value = n;
                                if (items[id].valEl) items[id].valEl.textContent = n;
                                if (items[id].row) {
                                    items[id].row.classList.toggle('eq-row--active', n > 0);
                                }
                                refresh();
                            }

                            function total() {
                                var sum = 0;
                                Object.keys(items).forEach(function (id) {
                                    var q = qty(id);
                                    var p = parseFloat(items[id].input.getAttribute('data-price') || '0');
                                    if (q > 0 && !isNaN(p)) sum += q * p;
                                });
                                return sum;
                            }

                            function totalCount() {
                                var n = 0;
                                Object.keys(items).forEach(function (id) { n += qty(id); });
                                return n;
                            }

                            function refresh() {
                                var t = total();
                                var count = totalCount();
                                if (drawerTotal) drawerTotal.textContent = fmt(t);
                                if (totalOut)    totalOut.textContent    = fmt(t);
                                if (badge) {
                                    if (count > 0) {
                                        badge.textContent = count;
                                        badge.hidden = false;
                                    } else {
                                        badge.hidden = true;
                                    }
                                }
                            }

                            function refreshSummary() {
                                if (!summary || !summaryLines) return;
                                var lines = [];
                                Object.keys(items).forEach(function (id) {
                                    var q = qty(id);
                                    if (q > 0) {
                                        var name  = items[id].input.getAttribute('data-name') || '';
                                        var price = parseFloat(items[id].input.getAttribute('data-price') || '0');
                                        lines.push({ q: q, name: name, sub: (q * price).toFixed(2) });
                                    }
                                });
                                if (lines.length) {
                                    summaryLines.innerHTML = lines.map(function (l) {
                                        return '<li class="eq-picker__line">' +
                                            '<span class="eq-picker__line-name">' + l.q + ' × ' + escHtml(l.name) + '</span>' +
                                            '<span class="eq-picker__line-sub">' + currency + ' ' + l.sub + '</span>' +
                                            '</li>';
                                    }).join('');
                                    summary.hidden = false;
                                } else {
                                    summary.hidden = true;
                                }
                            }

                            function escHtml(s) {
                                return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                            }

                            // ---- Focus trap ----

                            function focusableEls() {
                                return Array.prototype.slice.call(
                                    panel.querySelectorAll(
                                        'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'
                                    )
                                );
                            }

                            function trapFocus(e) {
                                if (e.key !== 'Tab') return;
                                var els = focusableEls();
                                if (!els.length) { e.preventDefault(); return; }
                                var first = els[0], last = els[els.length - 1];
                                if (e.shiftKey) {
                                    if (document.activeElement === first) { e.preventDefault(); last.focus(); }
                                } else {
                                    if (document.activeElement === last) { e.preventDefault(); first.focus(); }
                                }
                            }

                            // ---- Open / close ----

                            var prevFocus = null;

                            function open() {
                                prevFocus = document.activeElement;
                                drawer.hidden = false;
                                drawer.classList.remove('eq-drawer--closing');
                                document.body.style.overflow = 'hidden';
                                // Focus the first stepper button
                                var first = focusableEls()[0];
                                if (first) setTimeout(function () { first.focus(); }, 50);
                                document.addEventListener('keydown', onKeyDown);
                            }

                            function close() {
                                drawer.classList.add('eq-drawer--closing');
                                document.body.style.overflow = '';
                                document.removeEventListener('keydown', onKeyDown);
                                // Wait for CSS animation to finish, then hide
                                var dur = window.matchMedia('(min-width: 640px)').matches ? 180 : 220;
                                setTimeout(function () {
                                    drawer.hidden = true;
                                    drawer.classList.remove('eq-drawer--closing');
                                    if (prevFocus) prevFocus.focus();
                                }, dur);
                            }

                            function onKeyDown(e) {
                                if (e.key === 'Escape') close();
                                else trapFocus(e);
                            }

                            // ---- Stepper clicks (event delegation on the drawer body) ----

                            drawer.addEventListener('click', function (e) {
                                var btn = e.target.closest('.eq-stepper__btn');
                                if (!btn) return;
                                var row = btn.closest('.eq-row');
                                if (!row) return;
                                var id = row.getAttribute('data-id');
                                if (!items[id]) return;
                                var action = btn.getAttribute('data-action');
                                setQty(id, qty(id) + (action === 'inc' ? 1 : -1));
                            });

                            // ---- Controls ----

                            if (pickerBtn)  pickerBtn.addEventListener('click', open);
                            if (editBtn)    editBtn.addEventListener('click', open);
                            if (closeBtn)   closeBtn.addEventListener('click', close);
                            if (backdrop)   backdrop.addEventListener('click', close);
                            if (confirmBtn) confirmBtn.addEventListener('click', function () {
                                close();
                                setTimeout(refreshSummary, 240);
                            });

                            // Initial state
                            refresh();

                            // ---- Thumbnail lightbox ----

                            var lightbox    = document.getElementById('eq-lightbox');
                            var lightboxImg = document.getElementById('eq-lightbox-img');

                            if (lightbox && lightboxImg) {
                                drawer.addEventListener('click', function (e) {
                                    var thumb = e.target.closest('.eq-thumb');
                                    if (!thumb) return;
                                    var src = thumb.getAttribute('data-full') || thumb.src;
                                    lightboxImg.src = src;
                                    lightboxImg.alt = thumb.alt;
                                    lightbox.hidden = false;
                                    lightbox.removeAttribute('aria-hidden');
                                });

                                function closeLightbox() {
                                    lightbox.hidden = true;
                                    lightbox.setAttribute('aria-hidden', 'true');
                                    lightboxImg.src = '';
                                }

                                lightbox.addEventListener('click', closeLightbox);
                                document.addEventListener('keydown', function (e) {
                                    if (e.key === 'Escape' && !lightbox.hidden) {
                                        e.stopPropagation();
                                        closeLightbox();
                                    }
                                }, true);
                            }
                        }());
                        </script>
                    @endif

                    @if ($viaWhatsApp)
                        <script>
                        (function () {
                            var btn = document.getElementById('eq-wa-btn');
                            if (!btn) return;
                            var waBase = {{ json_encode($waPhone) }};
                            var siteName = {{ json_encode($site->name) }};

                            function value(id) {
                                var el = document.getElementById(id);
                                return el ? (el.value || '').trim() : '';
                            }

                            btn.addEventListener('click', function () {
                                var msg = 'Hello ' + siteName + ',';
                                var name = value('eq-name');
                                if (name) msg += '\n\nName: ' + name;
                                if (value('eq-email')) msg += '\nEmail: ' + value('eq-email');
                                // The number to call back on. It was collected
                                // and then left out of the message, which on a
                                // food order is the one thing the shop needs.
                                if (value('eq-phone')) msg += '\nPhone: ' + value('eq-phone');
                                if (value('eq-in')) msg += '\nDate: ' + value('eq-in');
                                if (value('eq-out')) msg += '\nUntil: ' + value('eq-out');
                                if (value('eq-time')) msg += '\nTime: ' + value('eq-time');
                                if (value('eq-adults')) msg += '\nPeople: ' + value('eq-adults');
                                if (value('eq-address')) msg += '\nAddress: ' + value('eq-address');

                                var lines = [];
                                document.querySelectorAll('.enquiry__item-qty').forEach(function (input) {
                                    var qty = parseInt(input.value || '0', 10);
                                    if (qty > 0) lines.push(qty + ' × ' + input.getAttribute('data-name'));
                                });
                                if (lines.length) msg += '\n\n' + lines.join('\n');

                                if (value('eq-message')) msg += '\n\n' + value('eq-message');

                                // Update href before navigation so popup blockers
                                // can't strip the ?text= by falling back to bare href.
                                btn.href = waBase + '?text=' + encodeURIComponent(msg);
                            });
                        }());
                        </script>
                    @endif
                @endif
            </div>
        </div>
    </div>
</section>
