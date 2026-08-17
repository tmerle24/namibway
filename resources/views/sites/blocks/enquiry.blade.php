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

                        <div class="field">
                            <label for="eq-email">Email</label>
                            <input type="email" id="eq-email" name="email" @unless ($viaWhatsApp) required @endunless maxlength="255">
                        </div>

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
                            <div class="enquiry__items" id="eq-items" data-currency="{{ $items->currency }}">
                                @foreach ($items->sections() as $section)
                                    <h4 class="enquiry__items-head">{{ $section['section'] }}</h4>

                                    @foreach ($section['items'] as $item)
                                        <div class="enquiry__item">
                                            <div class="enquiry__item-text">
                                                <strong>{{ $item->name }}</strong>
                                                @if (filled($item->description))
                                                    <span class="note">{{ \Illuminate\Support\Str::limit($item->description, 90) }}</span>
                                                @endif
                                                <span class="enquiry__item-price">{{ $item->priceLabel() }}</span>
                                            </div>
                                            <input type="number"
                                                   class="enquiry__item-qty"
                                                   name="items[{{ $item->id }}]"
                                                   data-price="{{ $item->price }}"
                                                   data-name="{{ $item->name }}"
                                                   min="0" max="99" value="0"
                                                   aria-label="Quantity of {{ $item->name }}">
                                        </div>
                                    @endforeach
                                @endforeach

                                <p class="enquiry__total"><span>Total</span> <strong id="eq-total">{{ $items->currency }} 0.00</strong></p>
                            </div>

                            @if ($type->needsAddress())
                                <div class="field">
                                    <label for="eq-address">Delivery address</label>
                                    <textarea id="eq-address" name="address" rows="3" maxlength="500" required></textarea>
                                </div>
                            @endif
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
                            var box = document.getElementById('eq-items');
                            if (!box) return;
                            var out = document.getElementById('eq-total');
                            var currency = box.getAttribute('data-currency') || '';

                            function total() {
                                var sum = 0;
                                box.querySelectorAll('.enquiry__item-qty').forEach(function (input) {
                                    var qty = parseInt(input.value || '0', 10);
                                    var price = parseFloat(input.getAttribute('data-price') || '0');
                                    if (qty > 0 && !isNaN(price)) sum += qty * price;
                                });
                                out.textContent = currency + ' ' + sum.toFixed(2);
                            }

                            box.addEventListener('input', total);
                            total();
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

                            btn.addEventListener('click', function (e) {
                                e.preventDefault();

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

                                window.open(waBase + '?text=' + encodeURIComponent(msg), '_blank', 'noopener');
                            });
                        }());
                        </script>
                    @endif
                @endif
            </div>
        </div>
    </div>
</section>
