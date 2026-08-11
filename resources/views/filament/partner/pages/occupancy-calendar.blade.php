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
                    The calendar shows one accommodation property at a time. None is linked to this account yet —
                    once a lodge, camp or guesthouse is on your account it appears here, and in the property
                    switcher at the top of the page.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="nw-toolbar nw-noprint">
                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" wire:click="shift(-{{ $stepDays }})">
                            &larr; Earlier
                        </button>
                        <button type="button" class="nw-btn" wire:click="today">Today</button>
                        <button type="button" class="nw-btn" wire:click="shift({{ $stepDays }})">
                            Later &rarr;
                        </button>

                        <span class="nw-range">
                            {{ $grid->from->isoFormat('D MMM YYYY') }} &ndash;
                            {{ $grid->to->copy()->subDay()->isoFormat('D MMM YYYY') }}
                        </span>
                    </div>

                    <div class="nw-toolbar__group">
                        <span class="nw-hint">
                            {{ $grid->occupancyPercent() }}% sold over these {{ $grid->columnCount() }} nights
                        </span>
                    </div>
                </div>

                @if ($grid->isEmpty())
                    <p class="nw-hint" style="margin-top: 1rem;">
                        {{ $property->name }} has no room types yet, so there is nothing to show a calendar for.
                        Room types — how many units of each, and what they cost a night — are what the calendar
                        is built from.
                    </p>
                @else
                    <div class="nw-cal" style="margin-top: 1rem;">
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
                                            <div class="nw-cal__name">{{ $row->roomType->name }}</div>
                                            <div class="nw-cal__meta">
                                                {{ $row->roomType->code }} ·
                                                {{ $row->roomType->total_units }} {{ Str::plural('unit', $row->roomType->total_units) }} ·
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
                                                                        wire:click="showReservation({{ $bar->id }})"
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
                                                                        wire:click="showBlock({{ $bar->id }})"
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
                                                        $facts = [
                                                            $cell->date->isoFormat('ddd D MMM'),
                                                            $cell->unitsFree.' of '.$cell->capacity.' free',
                                                            $cell->unitsSold.' sold',
                                                        ];

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

                                                    <div
                                                        @class([
                                                            'nw-cell',
                                                            'nw-cell--weekend' => $grid->columns[$index]->isWeekend,
                                                            'nw-cell--past' => $grid->columns[$index]->isPast,
                                                            'nw-cell--month' => $grid->columns[$index]->startsMonth,
                                                            'nw-cell--soldout' => $cell->isSoldOut(),
                                                            'nw-cell--overbooked' => $cell->isOverbooked(),
                                                        ])
                                                        title="{{ implode(' · ', $facts) }}"
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
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                @endforeach

                                <div class="nw-cal__foot">
                                    <div class="nw-cal__label">
                                        <div class="nw-cal__name">Free that night</div>
                                        <div class="nw-cal__meta">across every room type</div>
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
                    </div>
                @endif
            </x-filament::section>
        @endif

        @include('filament.partner.partials.detail-drawer', [
            'reservation' => $this->selectedReservation(),
            'block' => $this->selectedBlock(),
        ])
    </div>
</x-filament-panels::page>
