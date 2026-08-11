@php
    use App\Support\Money;

    /** @var \App\Services\Inventory\DTOs\DayGridData $day */
    $resolutions = $resolutions ?? [];
@endphp

{{--
    The day read down the hour axis, with each departure a block in its own
    column — the second of the two readings in BOOKING_SYSTEM.md → "Time inside
    a day".

    Not the night grid transposed, and DayGrid's docblock says why: the axis
    here carries no counters, only positions. Everything this file needs is on
    $day, already placed on the axis, so it does no time arithmetic and issues
    no queries.
--}}
<div class="nw-day">
    <div class="nw-day__bar nw-noprint">
        <span class="nw-hint">{{ $day->date->isoFormat('dddd D MMMM YYYY') }}</span>

        <span class="nw-day__spacer"></span>

        <span class="nw-hint">Rows:</span>

        @foreach ($resolutions as $minutes)
            <button
                type="button"
                class="nw-btn @if ($day->resolutionMinutes === $minutes) nw-btn--primary @endif"
                wire:click="showResolution({{ $minutes }})"
            >
                {{ $minutes }} min
            </button>
        @endforeach
    </div>

    <div class="nw-day__viewport">
        <div class="nw-day__table" style="--nw-day-cols: {{ $day->columnCount() }}; --nw-day-rows: {{ $day->rowCount }}">
            <div class="nw-day__head">
                <div class="nw-day__corner">
                    <div class="nw-cal__name">Time</div>
                    <div class="nw-cal__meta">{{ $day->resolutionMinutes }} min per row</div>
                </div>

                <div class="nw-day__heads">
                    @foreach ($day->columns as $column)
                        <div class="nw-day__colhead" title="{{ $column->unit->name }} — {{ $column->timeLabel() }}, {{ $column->durationLabel() }}">
                            <div class="nw-cal__name">{{ $column->label() }}</div>
                            <div class="nw-cal__meta">{{ $column->unit->name }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="nw-day__body">
                <div class="nw-day__axis">
                    @foreach ($day->axis() as $row)
                        <div @class(['nw-day__tick', 'nw-day__tick--hour' => $row['label'] !== null])>
                            {{ $row['label'] }}
                        </div>
                    @endforeach
                </div>

                <div class="nw-day__field">
                    {{--
                        The ruler: one line per row, drawn behind the blocks so
                        a departure that starts mid-hour is readable against
                        something.
                    --}}
                    @foreach ($day->axis() as $index => $row)
                        <div
                            @class(['nw-day__gridline', 'nw-day__gridline--hour' => $row['label'] !== null])
                            style="grid-row: {{ $index + 1 }}; grid-column: 1 / -1"
                            aria-hidden="true"
                        ></div>
                    @endforeach

                    @foreach ($day->columns as $index => $column)
                        @php
                            $facts = [
                                $column->timeLabel(),
                                $column->durationLabel(),
                                $column->seatsFree.' of '.$column->capacity.' seats free',
                                $column->seatsSold.' sold',
                            ];

                            if ($column->seatsBlocked) {
                                $facts[] = $column->seatsBlocked.' blocked';
                            }

                            $facts[] = Money::format($column->rate, $column->currency).' per seat';
                        @endphp

                        @php($sellable = $column->seatsFree > 0)

                        <div
                            @class([
                                'nw-departure',
                                'nw-departure--soldout' => $column->isSoldOut(),
                                'nw-departure--overbooked' => $column->isOverbooked(),
                                'nw-departure--clipped-end' => $column->clippedEnd,
                            ])
                            style="grid-column: {{ $index + 1 }}; grid-row: {{ $column->startIndex + 1 }} / span {{ $column->span }}"
                            title="{{ implode(' · ', $facts) }}"
                        >
                            <div class="nw-departure__head">
                                <span class="nw-departure__time">{{ $column->startsAt->format('H:i') }}</span>
                                <span class="nw-departure__seats">
                                    @if ($column->isOverbooked())
                                        {{ $column->seatsFree }}!
                                    @else
                                        {{ $column->seatsFree }}/{{ $column->capacity }}
                                    @endif
                                </span>
                            </div>

                            <div class="nw-departure__rate">{{ Money::format($column->rate, $column->currency) }}</div>

                            {{--
                                A departure with a seat left is where a booking
                                starts — the form opens knowing the unit, the
                                date and which departure, so a walk-in is two
                                clicks rather than a re-typed day. A full one
                                stays inert: offering a form that can only
                                refuse is a dead end, and the writer would
                                refuse it anyway.
                            --}}
                            @if ($sellable)
                                <button
                                    type="button"
                                    class="nw-departure__sell"
                                    wire:click="startDeparture({{ $column->unit->id }}, '{{ $day->date->toDateString() }}', {{ $column->slot->id }})"
                                    aria-label="Book a seat on {{ $column->label() }}, {{ $day->date->isoFormat('D MMMM YYYY') }}"
                                >
                                    + Seat
                                </button>
                            @endif

                            {{--
                                Who is on it. A seat sale has no span to draw,
                                so it is a chip inside the departure it belongs
                                to — and clicking it opens the same stay detail
                                the night grid opens.
                            --}}
                            @foreach ($column->stays as $stay)
                                <button
                                    type="button"
                                    class="nw-departure__stay nw-bar--{{ $stay->color() }}"
                                    wire:click="showReservation({{ $stay->reservationId }})"
                                    title="{{ $stay->label }} — {{ $stay->seats }} {{ Str::plural('seat', $stay->seats) }}, {{ $stay->stateLabel() }}"
                                >
                                    {{ $stay->label }} &times;{{ $stay->seats }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <p class="nw-hint" style="margin-top: 0.5rem;">
        Seats come off the departure they were sold on, so a full 09:00 tour says nothing about the 14:00 one.
        A departure running past midnight is drawn cut off at the bottom — its seats belong to the day it left on.
    </p>
</div>
