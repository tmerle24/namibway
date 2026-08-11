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

                        <span class="nw-hint">
                            {{ $grid->occupancyPercent() }}% sold over these {{ $grid->columnCount() }} nights
                        </span>

                        {{ $this->createBookingAction }}
                        {{ $this->createBlockAction }}
                    </div>
                </div>

                @if ($grid->isEmpty())
                    <p class="nw-hint" style="margin-top: 1rem;">
                        {{ $property->name }} has no room types yet, so there is nothing to show a calendar for.
                        Room types — how many units of each, and what they cost a night — are what the calendar
                        is built from.
                    </p>
                @else
                    @include('filament.partner.partials.occupancy-grid', ['grid' => $grid])
                @endif
            </x-filament::section>
        @endif

        @include('filament.partner.partials.detail-drawer', [
            'reservation' => $this->selectedReservation(),
            'block' => $this->selectedBlock(),
        ])
    </div>
</x-filament-panels::page>
