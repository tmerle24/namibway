@php
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    {{--
        Desk mode lives on the wrapper rather than on the button, because what
        goes fullscreen is the whole screen — grid, toolbar and drawer — and
        the drawer has to keep working while it is up.

        Native fullscreen is asked for and never depended on: it needs the
        click that is already happening, it can be refused, and iOS Safari does
        not have it for elements at all. The class is the desk mode; fullscreen
        is a bonus that also removes the browser's own chrome. Escape leaves
        either way — the browser fires fullscreenchange when it handled it, and
        the key handler covers the case where nothing did.
    --}}
    <div
        class="nw-lodge"
        x-data="{
            desk: false,
            toggle() {
                this.desk = ! this.desk;

                if (this.desk) {
                    /*
                     * Alpine's $el in an x-on handler refers to the element
                     * the handler is on (the button), not the x-data root.
                     * Walk up to the actual container so requestFullscreen()
                     * targets the whole calendar wrapper, not just the button.
                     */
                    this.$el.closest('.nw-lodge')?.requestFullscreen?.()?.catch(() => {});
                } else if (document.fullscreenElement) {
                    document.exitFullscreen?.()?.catch(() => {});
                }
            },
        }"
        x-bind:class="{ 'nw-lodge--desk': desk }"
        x-on:fullscreenchange.document="desk = !! document.fullscreenElement"
        x-on:keydown.escape.window="if (desk && ! document.fullscreenElement) desk = false"
    >
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

                        {{--
                            The screen at reception. Last in the row because it
                            is set once at the start of a shift, not used while
                            working — everything left of it is.
                        --}}
                        <button
                            type="button"
                            class="nw-btn"
                            x-on:click="toggle()"
                            x-bind:class="desk ? 'nw-btn--primary' : ''"
                            x-bind:aria-pressed="desk ? 'true' : 'false'"
                            x-bind:title="desk ? 'Back to the panel (Esc)' : 'Fill the screen with the calendar'"
                        >
                            {{--
                                Two icons rather than one with x-if: inside an
                                <svg> the parser makes a <template> an SVG
                                element, which has no content to stamp out.
                            --}}
                            <svg class="nw-btn__icon" x-show="! desk" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12.5 3H17v4.5" /><path d="M7.5 17H3v-4.5" /><path d="M17 3l-6 6" /><path d="M3 17l6-6" />
                            </svg>

                            <svg class="nw-btn__icon" x-show="desk" style="display: none;" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M16.5 8H12V3.5" /><path d="M3.5 12H8v4.5" /><path d="M12 8l5-5" /><path d="M8 12l-5 5" />
                            </svg>

                            <span x-text="desk ? 'Leave desk mode' : 'Desk mode'">Desk mode</span>
                        </button>

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
