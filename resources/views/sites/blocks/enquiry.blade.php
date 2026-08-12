@php
    use App\Sites\Blocks\EnquiryBlock;

    $stay = ($data['mode'] ?? EnquiryBlock::MODE_STAY) === EnquiryBlock::MODE_STAY;
    $today = \Carbon\CarbonImmutable::today()->toDateString();
    $sent = request()->query('sent') === '1';
    $failed = request()->query('sent') === '0';
@endphp
<section class="section section--tint" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="split reveal">
            <div>
                @if (filled($data['heading'] ?? null))
                    <h2>{{ $data['heading'] }}</h2>
                @endif

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
                        <p class="enquiry__failed">Something in the form was incomplete — please check the dates and your email address and send it again.</p>
                    @endif

                    {{-- A plain POST, no session and no CSRF token. The page
                         carries no cookie at all, which is most of why it is
                         small, and a token would mean issuing a session to
                         every reader of a public marketing page. What guards it
                         instead: a rate limit on the route, and a honeypot no
                         human ever fills in. There is nothing authenticated
                         here to forge. --}}
                    <form method="post" action="{{ $enquiryAction }}" class="enquiry__form">
                        <div class="field">
                            <label for="eq-name">Full name</label>
                            <input type="text" id="eq-name" name="name" required maxlength="255">
                        </div>

                        <div class="field">
                            <label for="eq-email">Email</label>
                            <input type="email" id="eq-email" name="email" required maxlength="255">
                        </div>

                        <div class="field">
                            <label for="eq-phone">Phone (optional)</label>
                            <input type="tel" id="eq-phone" name="phone" maxlength="50">
                        </div>

                        <div class="enquiry__row">
                            <div class="field">
                                <label for="eq-in">{{ $stay ? 'Arrival' : 'Date' }}</label>
                                <input type="date" id="eq-in" name="check_in" min="{{ $today }}" required>
                            </div>

                            @if ($stay)
                                <div class="field">
                                    <label for="eq-out">Departure</label>
                                    <input type="date" id="eq-out" name="check_out" min="{{ $today }}" required>
                                </div>
                            @else
                                {{-- No departure, and a time instead. Asking a
                                     restaurant guest when they are leaving is
                                     how a form says it was not written for
                                     them. --}}
                                <div class="field">
                                    <label for="eq-time">Time</label>
                                    <input type="time" id="eq-time" name="time">
                                </div>
                            @endif
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

                        <button class="btn enquiry__submit" type="submit">Send request</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</section>
