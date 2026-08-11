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
                    {{-- What the kitchen needs before anything else. --}}
                    @foreach ($board->boardTonight() as $basis => $rooms)
                        <div>
                            <div class="nw-stats__value nw-num">{{ $rooms }}</div>
                            {{ Str::plural('room', $rooms) }} on {{ $basis }}
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            @foreach ([
                ['Arriving', $board->arrivals, 'Nobody is due to arrive on this date.'],
                ['Departing', $board->departures, 'Nobody is due to leave on this date.'],
                ['Staying on', $board->inHouse, 'Nobody is staying through this date.'],
            ] as [$heading, $reservations, $emptyMessage])
                <x-filament::section>
                    <x-slot name="heading">{{ $heading }} ({{ $reservations->count() }})</x-slot>

                    @if ($reservations->isEmpty())
                        <p class="nw-empty">{{ $emptyMessage }}</p>
                    @else
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
                                        </td>
                                        <td>
                                            @foreach ($reservation->units as $unit)
                                                <div>{{ $unit->roomType?->name ?? '—' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif</div>
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
