@php
    /** @var \App\Services\Inventory\DTOs\ArrivalsBoardData|null $board */
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $isToday ? 'Arriving today' : 'Arriving ' . $board?->date->isoFormat('ddd D MMM') }}
        </x-slot>

        <x-slot name="description">
            {{ $property?->name }}
        </x-slot>

        <div class="nw-lodge">
            @include('filament.partner.partials.lodge-styles')

            @if (! $board)
                <p class="nw-hint">
                    No property is linked to this account yet, so there is nothing arriving.
                </p>
            @else
                <div class="nw-toolbar">
                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" wire:click="shift(-1)">&larr; Previous</button>
                        <button type="button" class="nw-btn" wire:click="today">Today</button>
                        <button type="button" class="nw-btn" wire:click="shift(1)">Next &rarr;</button>

                        <span class="nw-range">{{ $board->date->isoFormat('dddd D MMMM') }}</span>
                    </div>

                    <div class="nw-toolbar__group">
                        <a class="nw-btn" href="{{ $this->boardUrl() }}">
                            Arrivals and departures &rarr;
                        </a>
                    </div>
                </div>

                <div class="nw-stats" style="margin-top: 0.9rem;">
                    <div>
                        <div class="nw-stats__value nw-num">{{ $board->arrivals->count() }}</div>
                        arriving
                    </div>
                    <div>
                        <div class="nw-stats__value nw-num">{{ $board->departures->count() }}</div>
                        departing
                    </div>
                    <div>
                        <div class="nw-stats__value nw-num">{{ $board->unitsStayingTonight() }}</div>
                        rooms occupied tonight
                    </div>
                    <div>
                        <div class="nw-stats__value nw-num">{{ $board->guestsStayingTonight() }}</div>
                        guests tonight
                    </div>
                </div>

                @if ($board->arrivals->isEmpty())
                    <p class="nw-hint" style="margin-top: 0.9rem;">
                        Nobody is expected on {{ $board->date->isoFormat('D MMMM') }}.
                    </p>
                @else
                    <table class="nw-table" style="margin-top: 0.9rem;">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Rooms</th>
                                <th>Nights</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($board->arrivals as $reservation)
                                <tr>
                                    <td>
                                        {{ $reservation->guest_name }}
                                        <div class="nw-hint">{{ $reservation->reference }}</div>
                                    </td>
                                    <td>
                                        @foreach ($reservation->units as $unit)
                                            {{ $unit->roomType?->name ?? '—' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif{{ ! $loop->last ? ', ' : '' }}
                                        @endforeach
                                    </td>
                                    <td class="nw-num">{{ $reservation->nights() }}</td>
                                    <td>
                                        <span class="nw-badge nw-badge--{{ $reservation->status->color() }}">
                                            {{ $reservation->status->label() }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
