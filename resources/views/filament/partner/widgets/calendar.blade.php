@php
    /** @var \App\Services\Inventory\DTOs\OccupancyGridData|null $grid */
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">The next two weeks</x-slot>

        <x-slot name="description">
            {{ $property?->name }}
        </x-slot>

        <div class="nw-lodge nw-lodge--widget">
            @include('filament.partner.partials.lodge-styles')

            @if (! $grid)
                <p class="nw-hint">No property is linked to this account yet.</p>
            @elseif ($grid->isEmpty())
                <p class="nw-hint">
                    {{ $property->name }} has no room types yet, so there is nothing to show a calendar for.
                </p>
            @else
                <div class="nw-toolbar">
                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" wire:click="shift(-7)">&larr; Earlier</button>
                        <button type="button" class="nw-btn" wire:click="today">Today</button>
                        <button type="button" class="nw-btn" wire:click="shift(7)">Later &rarr;</button>

                        <span class="nw-range">
                            {{ $grid->from->isoFormat('D MMM') }} &ndash;
                            {{ $grid->to->copy()->subDay()->isoFormat('D MMM') }}
                        </span>
                    </div>

                    <div class="nw-toolbar__group">
                        <span class="nw-hint">{{ $grid->occupancyPercent() }}% sold</span>

                        <a class="nw-btn" href="{{ $this->calendarUrl() }}">Open the calendar &rarr;</a>
                    </div>
                </div>

                {{-- Read only: see the widget class for why a booking form must
                     not open from the landing page. --}}
                @include('filament.partner.partials.occupancy-grid', [
                    'grid' => $grid,
                    'bookable' => false,
                    'showLegend' => false,
                ])
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
