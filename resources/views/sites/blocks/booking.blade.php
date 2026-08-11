@php
    /** @var \App\Sites\Rendering\BookingPanelData $booking */
    $today = \Carbon\CarbonImmutable::today()->toDateString();
@endphp
<section class="section" id="{{ $anchor }}">
    <div class="wrap">
        @include('sites.partials.rule', ['label' => $definition->label()])

        <div class="reveal">
            @if (filled($data['heading'] ?? null))
                <h2>{{ $data['heading'] }}</h2>
            @endif

            @if (filled($data['intro'] ?? null))
                <p class="prose">{{ $data['intro'] }}</p>
            @endif

            <div class="booking" style="margin-top: var(--s5)">
                {{-- A plain GET form. No JavaScript, no session, no CSRF token:
                     the dates arrive in the query string and the page renders
                     again with live prices. Progressive enhancement is not a
                     compromise here, it is the cheapest thing that works. --}}
                <form class="booking__form" method="get" action="#{{ $anchor }}">
                    <div class="field">
                        <label for="check_in">Arrive</label>
                        <input type="date" id="check_in" name="check_in" min="{{ $today }}"
                               value="{{ $booking->checkIn?->toDateString() }}">
                    </div>
                    <div class="field">
                        <label for="check_out">Depart</label>
                        <input type="date" id="check_out" name="check_out" min="{{ $today }}"
                               value="{{ $booking->checkOut?->toDateString() }}">
                    </div>
                    <div class="field">
                        <label for="adults">Adults</label>
                        <input type="number" id="adults" name="adults" min="1" max="20" value="{{ $booking->adults }}">
                    </div>
                    <div class="field">
                        <label for="children">Children</label>
                        <input type="number" id="children" name="children" min="0" max="20" value="{{ $booking->children }}">
                    </div>
                    <button class="btn" type="submit">Check</button>
                </form>

                @if ($booking->hasQuery())
                    <div class="offers">
                        @forelse ($booking->offers as $offer)
                            <div class="offer">
                                <div>
                                    <h3 style="margin-bottom:2px">{{ $offer->bookableUnit->name }}</h3>
                                    <p class="offer__meta" style="margin:0">
                                        {{ $booking->nights() }} {{ \Illuminate\Support\Str::plural('night', $booking->nights()) }}
                                        @if ($offer->unitsLeft <= 3)
                                            · {{ $offer->unitsLeft }} left
                                        @endif
                                    </p>
                                </div>
                                <div class="offer__price">
                                    {{-- Both figures, always. One of them alone
                                         either hides a park permit or double
                                         counts an inclusive tax. --}}
                                    <strong>{{ $offer->currency }} {{ number_format($offer->totalPayable, 2) }}</strong>
                                    <div class="offer__meta">
                                        {{ $offer->currency }} {{ number_format($offer->averageNightly(), 2) }} per night
                                    </div>
                                </div>
                            </div>
                        @empty
                            <p class="note">Nothing free for those dates. Try a different arrival, or send us a message.</p>
                        @endforelse

                        @if ($booking->offers !== [])
                            <p style="margin-top: var(--s5)">
                                <a class="btn" href="{{ $booking->bookingUrl() }}">Request these dates</a>
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>
