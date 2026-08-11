@php
    use App\Support\Money;

    /** @var \App\Services\Inventory\DTOs\OccupancyGridData $grid */
    // $bookable — whether the grid is interactive: a free cell starts a
    // booking, a bar opens its detail. False on the dashboard widget, which is
    // a glance at the book and not a place to work in it — and which has
    // neither the booking form nor the detail drawer behind it.
    $bookable = $bookable ?? true;
    $showLegend = $showLegend ?? true;
    // Sized to content rather than to a minimum: set where the grid shares the
    // page with the hour axis, so the thing somebody opened the day view for is
    // not pushed below an empty frame.
    $compact = $compact ?? false;
@endphp

{{--
    The grid itself: room types down, nights across, stays and blocks as bars.

    Shared by the full Calendar page and the dashboard widget so the two cannot
    drift into showing the same book differently. Everything it needs is on
    $grid, already clipped and lane-packed by OccupancyGrid — this file does no
    date arithmetic and issues no queries.
--}}
<div @class(['nw-cal', 'nw-cal--compact' => $compact])>
    <div class="nw-cal__viewport">
        <div class="nw-cal__table" style="--nw-cols: {{ $grid->columnCount() }}">
            <div class="nw-cal__head">
                <div class="nw-cal__label">
                    <div class="nw-cal__name">Room type</div>
                    <div class="nw-cal__meta">free · rate per night</div>
                </div>

                <div class="nw-cal__days">
                    @foreach ($grid->columns as $column)
                        <div
                            @class([
                                'nw-cal__dayhead',
                                'nw-cal__dayhead--weekend' => $column->isWeekend,
                                'nw-cal__dayhead--past' => $column->isPast,
                                'nw-cal__dayhead--today' => $column->isToday,
                                'nw-cal__dayhead--month' => $column->startsMonth,
                            ])
                            title="{{ $column->date->isoFormat('dddd D MMMM YYYY') }}"
                        >
                            <div class="nw-cal__dow">{{ $column->date->isoFormat('dd') }}</div>
                            <div class="nw-cal__dom">{{ $column->date->format('j') }}</div>
                            @if ($column->startsMonth)
                                <div class="nw-cal__dow">{{ $column->date->isoFormat('MMM') }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            @foreach ($grid->rows as $row)
                <div @class(['nw-cal__row', 'nw-cal__retired' => $row->isRetired])>
                    <div class="nw-cal__label">
                        <div class="nw-cal__name">{{ $row->bookableUnit->name }}</div>
                        <div class="nw-cal__meta">
                            {{ $row->bookableUnit->code }} ·
                            @if ($row->isDepartureUnit())
                                {{ $row->bookableUnit->total_units }} seats per departure ·
                            @else
                                {{ $row->bookableUnit->total_units }} {{ Str::plural('unit', $row->bookableUnit->total_units) }} ·
                            @endif
                            {{ Money::symbol($row->currency) }}
                            @if ($row->isRetired)
                                · inactive
                            @endif
                        </div>
                    </div>

                    <div class="nw-cal__body">
                        @if ($row->hasAnyBar())
                            <div class="nw-cal__lanes">
                                @foreach ($row->lanes as $lane)
                                    <div class="nw-cal__lane">
                                        @foreach ($lane as $bar)
                                            @php
                                                $units = $bar->units.' '.Str::plural('unit', $bar->units);
                                                $label = $bar->units > 1 ? $bar->label.' ×'.$bar->units : $bar->label;
                                            @endphp

                                            @if ($bar->isReservation())
                                                <button
                                                    type="button"
                                                    @class([
                                                        'nw-bar',
                                                        'nw-bar--' . $bar->state->color(),
                                                        'nw-bar--clipped-start' => $bar->clippedStart,
                                                        'nw-bar--clipped-end' => $bar->clippedEnd,
                                                    ])
                                                    style="grid-column: {{ $bar->startIndex + 1 }} / span {{ $bar->span }}"
                                                    @disabled(! $bookable)
                                                    @if ($bookable) wire:click="showReservation({{ $bar->id }})" @endif
                                                    title="{{ $bar->label }} — {{ $units }}, {{ $bar->nights() }} {{ Str::plural('night', $bar->nights()) }}, {{ $bar->stateLabel() }} ({{ $bar->checkIn->isoFormat('D MMM') }} – {{ $bar->checkOut->isoFormat('D MMM') }})"
                                                >
                                                    {{ $label }}
                                                </button>
                                            @else
                                                <button
                                                    type="button"
                                                    @class([
                                                        'nw-bar',
                                                        'nw-bar--block',
                                                        'nw-bar--clipped-start' => $bar->clippedStart,
                                                        'nw-bar--clipped-end' => $bar->clippedEnd,
                                                    ])
                                                    style="grid-column: {{ $bar->startIndex + 1 }} / span {{ $bar->span }}"
                                                    @disabled(! $bookable)
                                                    @if ($bookable) wire:click="showBlock({{ $bar->id }})" @endif
                                                    title="{{ $bar->label }} — {{ $units }} off sale ({{ $bar->checkIn->isoFormat('D MMM') }} – {{ $bar->checkOut->copy()->subDay()->isoFormat('D MMM') }})"
                                                >
                                                    {{ $label }}
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="nw-cal__cells">
                            @foreach ($row->cells as $index => $cell)
                                @php
                                    // Built here rather than inline: a tooltip stitched together
                                    // from @if directives inside an attribute is where this view
                                    // is hardest to read and easiest to break.
                                    // A unit sold by departure counts seats
                                    // across its timetable, so the words have
                                    // to change with it — "2 of 16 free" on a
                                    // quad tour is seats, not quads.
                                    $facts = [
                                        $cell->date->isoFormat('ddd D MMM'),
                                        $cell->unitsFree.' of '.$cell->capacity
                                            .($cell->isDepartureDay() ? ' seats free' : ' free'),
                                        $cell->unitsSold.' sold',
                                    ];

                                    if ($cell->isDepartureDay()) {
                                        $facts[] = $cell->departures.' '.Str::plural('departure', $cell->departures);
                                    }

                                    if ($cell->unitsBlocked) {
                                        $facts[] = $cell->unitsBlocked.' blocked';
                                    }

                                    $facts[] = Money::format($cell->rate, $row->currency);

                                    if ($cell->closedToArrival) {
                                        $facts[] = 'no arrivals';
                                    }

                                    if ($cell->closedToDeparture) {
                                        $facts[] = 'no departures';
                                    }

                                    if ($cell->minStay > 1) {
                                        $facts[] = 'minimum '.$cell->minStay.' nights';
                                    }
                                @endphp

                                {{--
                                    A night with something left is where a booking starts:
                                    clicking it opens the form already knowing the room type
                                    and the arrival date, which is the difference between
                                    taking a walk-in in two clicks and re-typing what is
                                    already on the screen. A night with nothing free stays
                                    inert — offering a form that can only refuse would be a
                                    dead end, and the writer would refuse it anyway.
                                --}}
                                {{--
                                    A departure day is inert here even when
                                    seats are free. The booking form sells
                                    nights, and a click that quietly took a
                                    seat off the property's own counter
                                    instead of off the 09:00 tour would put
                                    the calendar wrong in the one way this
                                    whole design exists to prevent. Selling a
                                    departure is the day view's job, and it is
                                    the next step — see BOOKING_SYSTEM.md.
                                --}}
                                @php($cellBookable = $bookable && $cell->unitsFree > 0 && ! $cell->isDepartureDay())

                                <button
                                    type="button"
                                    @disabled(! $cellBookable)
                                    @if ($cellBookable)
                                        wire:click="startBooking({{ $row->bookableUnit->id }}, '{{ $cell->date->toDateString() }}')"
                                        aria-label="Book {{ $row->bookableUnit->name }}, arriving {{ $cell->date->isoFormat('D MMMM YYYY') }}"
                                    @endif
                                    @class([
                                        'nw-cell',
                                        'nw-cell--bookable' => $cellBookable,
                                        'nw-cell--weekend' => $grid->columns[$index]->isWeekend,
                                        'nw-cell--past' => $grid->columns[$index]->isPast,
                                        'nw-cell--month' => $grid->columns[$index]->startsMonth,
                                        'nw-cell--soldout' => $cell->isSoldOut(),
                                        'nw-cell--overbooked' => $cell->isOverbooked(),
                                    ])
                                    title="{{ implode(' · ', $facts) }}{{ $cellBookable ? ' · click to book' : '' }}"
                                >
                                    @if ($cell->closedToArrival)
                                        <span class="nw-cell__cta" aria-hidden="true"></span>
                                    @endif

                                    @if ($cell->closedToDeparture)
                                        <span class="nw-cell__ctd" aria-hidden="true"></span>
                                    @endif

                                    <div class="nw-cell__free">
                                        @if ($cell->isOverbooked())
                                            {{ $cell->unitsFree }}!
                                        @else
                                            {{ $cell->unitsFree }}
                                        @endif
                                    </div>

                                    <div class="nw-cell__rate">{{ number_format($cell->rate, 0) }}</div>

                                    @if ($cell->minStay > 1)
                                        <span class="nw-cell__minstay">{{ $cell->minStay }}+</span>
                                    @endif

                                    @if ($cell->isDepartureDay())
                                        <span class="nw-cell__departures">&times;{{ $cell->departures }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="nw-cal__foot">
                <div class="nw-cal__label">
                    <div class="nw-cal__name">Free that night</div>
                    <div class="nw-cal__meta">
                        @if ($grid->hasDepartureUnits())
                            rooms and seats, across every room type
                        @else
                            across every room type
                        @endif
                    </div>
                </div>

                <div class="nw-cal__days">
                    @foreach ($grid->columns as $column)
                        <div
                            @class([
                                'nw-cal__total',
                                'nw-cal__total--full' => $column->unitsFree <= 0,
                            ])
                            title="{{ $column->date->isoFormat('ddd D MMM') }} — {{ $column->occupancyPercent() }}% sold"
                        >
                            {{ $column->unitsFree }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@if ($showLegend)

    <div class="nw-legend nw-noprint" style="margin-top: 0.75rem;">
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--success"></span> Confirmed
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--primary"></span> In house
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--info"></span> Due in
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--warning"></span> Provisional
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--danger"></span> No show
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-bar--block"></span> Off sale
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-cell--soldout"></span> Sold out
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch nw-cell--overbooked"></span> Overbooked
        </span>
        <span class="nw-legend__item">
            <span class="nw-legend__swatch" style="border-top: 7px solid rgb(var(--danger-500, 239 68 68)); height: 0; width: 0; border-right: 7px solid transparent; border-radius: 0;"></span>
            Closed to arrival (left corner) or departure (right)
        </span>
        <span class="nw-legend__item">
            <strong style="color: rgb(var(--warning-600, 217 119 6));">2+</strong> Minimum stay
        </span>
    </div>
@endif
</div>
