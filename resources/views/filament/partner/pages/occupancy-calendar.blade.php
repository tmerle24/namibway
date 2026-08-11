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
                        <button type="button" class="nw-btn" wire:click="shift(-1)" aria-label="Previous {{ Str::lower($activeRange->label()) }}">
                            &larr;
                        </button>
                        <button type="button" class="nw-btn" wire:click="today">Today</button>
                        <button type="button" class="nw-btn" wire:click="shift(1)" aria-label="Next {{ Str::lower($activeRange->label()) }}">
                            &rarr;
                        </button>

                        <span class="nw-range">{{ $rangeLabel }}</span>

                        {{--
                            A month and a year to jump to. Both selects post
                            both values, so choosing one never silently resets
                            the other.
                        --}}
                        <select
                            class="nw-select"
                            aria-label="Month"
                            wire:change="jumpTo($event.target.value, {{ $jumpYear }})"
                        >
                            @foreach ($months as $number => $name)
                                <option value="{{ $number }}" @selected($number === $jumpMonth)>{{ $name }}</option>
                            @endforeach
                        </select>

                        <select
                            class="nw-select nw-select--year"
                            aria-label="Year"
                            wire:change="jumpTo({{ $jumpMonth }}, $event.target.value)"
                        >
                            @foreach ($years as $year)
                                <option value="{{ $year }}" @selected($year === $jumpYear)>{{ $year }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="nw-toolbar__group">
                        {{--
                            How much is on screen. The same book either way —
                            a range is a reading of the rows, not a second
                            calendar.
                        --}}
                        @foreach ($ranges as $option)
                            <button
                                type="button"
                                class="nw-btn @if ($activeRange === $option) nw-btn--primary @endif"
                                wire:click="showRange('{{ $option->value }}')"
                            >
                                {{ $option->label() }}
                            </button>
                        @endforeach
                    </div>

                    <div class="nw-toolbar__group">
                        {{--
                            Only when the property sells more than one product.
                            Availability is the same whichever is chosen — a
                            room is sold once — so this changes the prices in
                            the cells and nothing else.
                        --}}
                        @if ($ratePlans !== [])
                            <span class="nw-hint">Rates:</span>

                            @foreach ($ratePlans as $plan)
                                <button
                                    type="button"
                                    class="nw-btn @if ($shownRatePlan && $shownRatePlan->id === $plan->id) nw-btn--primary @endif"
                                    wire:click="showRatePlan({{ $plan->id }})"
                                >
                                    {{ $plan->label() }}
                                </button>
                            @endforeach
                        @endif

                        @if (! $grid->isEmpty())
                            <span class="nw-hint">
                                {{ $grid->occupancyPercent() }}% sold over
                                {{ $grid->columnCount() }} {{ Str::plural('night', $grid->columnCount()) }}
                            </span>
                        @endif

                        @if ($dayGrid)
                            <span class="nw-hint">
                                {{ $dayGrid->seatsSold() }} of {{ $dayGrid->capacity() }} seats sold
                            </span>
                        @endif

                        {{ $this->createBookingAction }}
                        {{ $this->createBlockAction }}
                    </div>
                </div>

                <div class="nw-printonly nw-range">
                    {{ $property->name }} — {{ $rangeLabel }}
                </div>

                @if ($grid->isEmpty() && ! $dayGrid)
                    <p class="nw-hint" style="margin-top: 1rem;">
                        {{ $property->name }} has no room types yet, so there is nothing to show a calendar for.
                        Room types — how many units of each, and what they cost a night — are what the calendar
                        is built from.
                    </p>
                @else
                    @if (! $grid->isEmpty())
                        @include('filament.partner.partials.occupancy-grid', [
                            'grid' => $grid,
                            'compact' => (bool) $dayGrid,
                        ])
                    @endif

                    {{--
                        The same day, read down the hour axis instead of across
                        the nights. Only where the property runs departures —
                        a lodge never reaches this.
                    --}}
                    @if ($dayGrid)
                        @include('filament.partner.partials.departure-grid', [
                            'day' => $dayGrid,
                            'resolutions' => $resolutions,
                        ])
                    @endif
                @endif
            </x-filament::section>
        @endif

        @include('filament.partner.partials.detail-drawer', [
            'reservation' => $this->selectedReservation(),
            'block' => $this->selectedBlock(),
        ])
    </div>
</x-filament-panels::page>
