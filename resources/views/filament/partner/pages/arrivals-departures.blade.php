@php
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    <div class="nw-lodge">
        @include('filament.partner.partials.lodge-styles')

        @if (! $property)
            <x-filament::section>
                <x-slot name="heading">No property yet</x-slot>

                <p class="nw-hint">
                    Arrivals and departures are shown for one accommodation property at a time, and none is linked
                    to this account yet.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="nw-toolbar nw-noprint">
                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" wire:click="shift(-1)">&larr; Previous day</button>
                        <button type="button" class="nw-btn" wire:click="today">Today</button>
                        <button type="button" class="nw-btn" wire:click="shift(1)">Next day &rarr;</button>
                    </div>

                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" onclick="window.print()">Print</button>
                    </div>
                </div>

                <div style="margin-top: 1rem;">
                    <div class="nw-range">
                        {{ $property->name }} — {{ $board->date->isoFormat('dddd D MMMM YYYY') }}
                        @unless ($board->date->isSameDay($board->today))
                            <span class="nw-hint">(today is {{ $board->today->isoFormat('D MMM') }})</span>
                        @endunless
                    </div>
                </div>

                <div class="nw-stats" style="margin-top: 1rem;">
                    @if ($board->sellsNights)
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->arrivals->count() }}</div>
                            arriving ({{ $board->units($board->arrivals) }} {{ Str::plural('room', $board->units($board->arrivals)) }})
                        </div>
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->departures->count() }}</div>
                            departing ({{ $board->units($board->departures) }} {{ Str::plural('room', $board->units($board->departures)) }})
                        </div>
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->inHouse->count() }}</div>
                            staying on
                        </div>
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->unitsStayingTonight() }}</div>
                            rooms occupied tonight
                        </div>
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->guestsStayingTonight() }}</div>
                            guests tonight
                        </div>
                    @endif
                    {{-- What the kitchen needs before anything else. --}}
                    @foreach ($board->boardTonight() as $basis => $rooms)
                        <div>
                            <div class="nw-stats__value nw-num">{{ $rooms }}</div>
                            {{ Str::plural('room', $rooms) }} on {{ $basis }}
                        </div>
                    @endforeach

                    @if ($board->hasManifests())
                        <div>
                            <div class="nw-stats__value nw-num">{{ count($board->runningToday()) }}</div>
                            {{ Str::plural('departure', count($board->runningToday())) }} with somebody on
                        </div>
                        <div>
                            <div class="nw-stats__value nw-num">{{ $board->seatsToday() }}</div>
                            seats out today
                        </div>
                    @endif
                </div>
            </x-filament::section>

            {{--
                The passenger lists, first because they leave first: a sunrise
                drive is at 06:30 and the first check-in is at two. Each one is
                its own section so a printed page breaks between departures
                rather than through one, and so a guide can be handed the sheet
                for their own vehicle.

                A lodge never reaches this — the property runs no departures,
                so there is nothing to list.
            --}}
            @foreach ($board->runningToday() as $departure)
                <x-filament::section>
                    <x-slot name="heading">
                        {{ $departure->startsAt->format('H:i') }} — {{ $departure->label() }}
                    </x-slot>

                    <x-slot name="description">
                        {{ $departure->unit->name }} ·
                        {{ $departure->timeLabel() }} ({{ $departure->durationLabel() }}) ·
                        {{ $departure->seatsSold }} of {{ $departure->capacity }} seats taken
                    </x-slot>

                    <div class="nw-scroll">
                        <table class="nw-table">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Seats</th>
                                    <th>Party</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($departure->stays as $stay)
                                    <tr>
                                        <td>
                                            <button
                                                type="button"
                                                class="nw-table__link"
                                                wire:click="showReservation({{ $stay->reservationId }})"
                                            >
                                                {{ $stay->label }}
                                            </button>
                                        </td>
                                        <td class="nw-num">{{ $stay->seats }}</td>
                                        <td class="nw-num">{{ $stay->party ?: '—' }}</td>
                                        {{--
                                            Printed, not only shown: a guide standing
                                            at the vehicle with somebody missing needs
                                            a number, and that is the whole reason this
                                            sheet leaves the office.
                                        --}}
                                        <td class="nw-num">{{ $stay->phone ?: '—' }}</td>
                                        <td>
                                            <span class="nw-badge nw-badge--{{ $stay->color() }}">
                                                {{ $stay->stateLabel() }}
                                            </span>
                                        </td>
                                        <td class="nw-num nw-hint">{{ $stay->reference }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endforeach

            {{--
                Only where something is sold by the night. An operator who runs
                only tours would otherwise get three headings saying "nobody" on
                every page they print — somebody else's board with the rows
                taken out.
            --}}
            @foreach ($board->sellsNights ? [
                ['Arriving', $board->arrivals, 'Nobody is due to arrive on this date.'],
                ['Departing', $board->departures, 'Nobody is due to leave on this date.'],
                ['Staying on', $board->inHouse, 'Nobody is staying through this date.'],
            ] : [] as [$heading, $reservations, $emptyMessage])
                <x-filament::section>
                    <x-slot name="heading">{{ $heading }} ({{ $reservations->count() }})</x-slot>

                    @if ($reservations->isEmpty())
                        <p class="nw-empty">{{ $emptyMessage }}</p>
                    @else
                        <div class="nw-scroll">
                            <table class="nw-table">
                                <thead>
                                    <tr>
                                        <th>Guest</th>
                                        <th>Room type</th>
                                        <th>Sold as</th>
                                        <th>Rooms</th>
                                        <th>Guests</th>
                                        <th>Arrival</th>
                                        <th>Departure</th>
                                        <th>Nights</th>
                                        <th>Status</th>
                                        {{-- Money is collected when a guest is
                                             standing there, so the number belongs
                                             on the list somebody works down at the
                                             counter rather than one screen away. --}}
                                        <th>Outstanding</th>
                                        <th>Reference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($reservations as $reservation)
                                        <tr>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="nw-table__link"
                                                    wire:click="showReservation({{ $reservation->id }})"
                                                >
                                                    {{ $reservation->guest_name }}
                                                </button>
                                                <div class="nw-hint">{{ $reservation->source->label() }}</div>
                                                {{-- The reason the note is a column and not a
                                                     line in a log: this list is what a room is
                                                     made up from. --}}
                                                @if (filled($reservation->over_capacity_note))
                                                    <div class="nw-warn">Extra bed: {{ $reservation->over_capacity_note }}</div>
                                                @endif
                                            </td>
                                            <td>
                                                @foreach ($reservation->units as $unit)
                                                    <div>{{ $unit->bookableUnit?->name ?? '—' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif</div>
                                                @endforeach
                                            </td>
                                            <td>
                                                @foreach ($reservation->units as $unit)
                                                    <div>{{ $unit->soldAs() ?? '—' }}</div>
                                                @endforeach
                                            </td>
                                            <td class="nw-num">{{ $reservation->units->sum('quantity') }}</td>
                                            <td class="nw-num">
                                                {{ $reservation->adults }}@if ($reservation->children)+{{ $reservation->children }}@endif
                                            </td>
                                            <td class="nw-num">{{ $reservation->check_in->isoFormat('ddd D MMM') }}</td>
                                            <td class="nw-num">{{ $reservation->check_out->isoFormat('ddd D MMM') }}</td>
                                            <td class="nw-num">{{ $reservation->nights() }}</td>
                                            <td>
                                                <span class="nw-badge nw-badge--{{ $reservation->status->color() }}">
                                                    {{ $reservation->status->label() }}
                                                </span>
                                            </td>
                                            {{-- Read off the stay, not summed here:
                                                 `paid_amount` is written down by
                                                 PaymentRecorder so this board and
                                                 the stay drawer cannot disagree. --}}
                                            <td class="nw-num">
                                                @if ($reservation->total_amount === null)
                                                    <span class="nw-hint">No price</span>
                                                @elseif ($reservation->payment_status->isSettled())
                                                    <span class="nw-hint">Paid</span>
                                                @else
                                                    {{ Money::format($reservation->total_amount - $reservation->paid_amount, $reservation->currency) }}
                                                @endif
                                            </td>
                                            <td class="nw-num nw-hint">
                                                {{ $reservation->reference }}
                                                @if ($reservation->total_amount !== null)
                                                    <div>{{ Money::format($reservation->total_amount, $reservation->currency) }}</div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        @endif

        @include('filament.partner.partials.detail-drawer', [
            'reservation' => $this->selectedReservation(),
            'block' => $this->selectedBlock(),
        ])
    </div>
</x-filament-panels::page>
