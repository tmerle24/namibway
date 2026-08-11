@php
    use App\Support\Money;

    /** @var \App\Models\Reservation|null $reservation */
    /** @var \App\Models\InventoryBlock|null $block */
    $reservation = $reservation ?? null;
    $block = $block ?? null;
@endphp

{{--
    The read-only detail both lodge screens open — a stay from a bar or a row,
    a block from a bar. Nothing in here changes anything: editing a booking is
    slice 3, and a button that did nothing would be a promise we have not kept.
--}}
@if ($reservation || $block)
    <div
        class="nw-drawer"
        role="dialog"
        aria-modal="true"
        wire:click.self="closeReservation"
        x-data
        x-on:keydown.escape.window="$wire.closeReservation()"
    >
        <div class="nw-drawer__panel">
            @if ($reservation)
                <div class="nw-drawer__head">
                    <div>
                        <div class="nw-drawer__title">{{ $reservation->guest_name }}</div>
                        <div class="nw-hint">
                            {{ $reservation->reference }} ·
                            <span class="nw-badge nw-badge--{{ $reservation->status->color() }}">
                                {{ $reservation->status->label() }}
                            </span>
                        </div>
                    </div>

                    <button type="button" class="nw-btn" wire:click="closeReservation">Close</button>
                </div>

                <dl class="nw-facts">
                    <dt>Arrival</dt>
                    <dd>{{ $reservation->check_in->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Departure</dt>
                    <dd>{{ $reservation->check_out->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Nights</dt>
                    <dd class="nw-num">{{ $reservation->nights() }}</dd>

                    <dt>Guests</dt>
                    <dd class="nw-num">
                        {{ $reservation->adults }} {{ Str::plural('adult', $reservation->adults) }}@if ($reservation->children), {{ $reservation->children }} {{ Str::plural('child', $reservation->children) }}@endif
                    </dd>

                    <dt>Booked via</dt>
                    <dd>{{ $reservation->source->label() }}</dd>

                    @if ($reservation->guest_email)
                        <dt>Email</dt>
                        <dd>{{ $reservation->guest_email }}</dd>
                    @endif

                    @if ($reservation->guest_phone)
                        <dt>Phone</dt>
                        <dd>{{ $reservation->guest_phone }}</dd>
                    @endif

                    @if ($reservation->total_amount !== null)
                        <dt>Total</dt>
                        <dd class="nw-num">{{ Money::format($reservation->total_amount, $reservation->currency) }}</dd>
                    @endif
                </dl>

                @if (filled($reservation->notes))
                    <div class="nw-subhead">Notes</div>
                    <p class="nw-hint">{{ $reservation->notes }}</p>
                @endif

                <div class="nw-subhead">Rooms</div>

                <table class="nw-table">
                    <thead>
                        <tr>
                            <th>Room type</th>
                            <th>Units</th>
                            <th>Dates</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservation->units as $unit)
                            <tr>
                                <td>{{ $unit->roomType?->name ?? '—' }}</td>
                                <td class="nw-num">{{ $unit->quantity }}</td>
                                <td class="nw-num">
                                    {{ $unit->check_in->isoFormat('D MMM') }} – {{ $unit->check_out->isoFormat('D MMM') }}
                                </td>
                                <td class="nw-num">{{ Money::format($unit->total_amount, $unit->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{--
                    The per-night breakdown is the answer to "why does this stay
                    cost that", and it is also where a season boundary becomes
                    visible: the rate simply changes on the night it changes.
                --}}
                <div class="nw-subhead">Per night</div>

                <table class="nw-table">
                    <thead>
                        <tr>
                            <th>Night</th>
                            <th>Room type</th>
                            <th>Units</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservation->units as $unit)
                            @foreach ($unit->nights->sortBy('date') as $night)
                                <tr>
                                    <td class="nw-num">{{ $night->date->isoFormat('ddd D MMM') }}</td>
                                    <td>{{ $unit->roomType?->name ?? '—' }}</td>
                                    <td class="nw-num">{{ $night->units }}</td>
                                    <td class="nw-num">{{ Money::format($night->rate, $night->currency) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="nw-drawer__head">
                    <div>
                        <div class="nw-drawer__title">{{ $block->reason->label() }}</div>
                        <div class="nw-hint">Rooms off sale — not a booking</div>
                    </div>

                    <button type="button" class="nw-btn" wire:click="closeReservation">Close</button>
                </div>

                <dl class="nw-facts">
                    <dt>Room type</dt>
                    <dd>{{ $block->roomType?->name ?? '—' }}</dd>

                    <dt>Units</dt>
                    <dd class="nw-num">{{ $block->units }}</dd>

                    <dt>First night</dt>
                    <dd>{{ $block->first_night->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Last night</dt>
                    <dd>{{ $block->last_night->isoFormat('ddd D MMM YYYY') }}</dd>
                </dl>

                @if (filled($block->note))
                    <div class="nw-subhead">Note</div>
                    <p class="nw-hint">{{ $block->note }}</p>
                @endif
            @endif
        </div>
    </div>
@endif
