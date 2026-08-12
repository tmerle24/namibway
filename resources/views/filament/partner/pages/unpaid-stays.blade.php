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
                    Unpaid stays are shown for one property at a time, and none is linked to this account yet.
                </p>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Still owing ({{ $stays->count() }})</x-slot>

                <x-slot name="description">
                    Everything at {{ $property->name }} that is not square, soonest arrival first — money is
                    collected while a guest is standing there. Open a stay to record what comes in.
                </x-slot>

                <div class="nw-toolbar nw-noprint">
                    <div class="nw-toolbar__group">
                        <button type="button" class="nw-btn" onclick="window.print()">Print</button>
                    </div>
                </div>

                @if ($stays->isEmpty())
                    <p class="nw-empty">Every stay is paid up. Nothing to chase.</p>
                @else
                    <div class="nw-scroll">
                        <table class="nw-table" style="margin-top: 1rem;">
                            <thead>
                                <tr>
                                    <th>Guest</th>
                                    <th>Room type</th>
                                    <th>Arrival</th>
                                    <th>Departure</th>
                                    <th>Stay</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Outstanding</th>
                                    <th>Reference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stays as $stay)
                                    <tr>
                                        <td>
                                            <button
                                                type="button"
                                                class="nw-table__link"
                                                wire:click="showReservation({{ $stay->id }})"
                                            >
                                                {{ $stay->guest_name }}
                                            </button>
                                            <div class="nw-hint">{{ $stay->source->label() }}</div>
                                        </td>
                                        <td>
                                            @foreach ($stay->units as $unit)
                                                <div>{{ $unit->bookableUnit?->name ?? '—' }}@if ($unit->quantity > 1) ×{{ $unit->quantity }}@endif</div>
                                            @endforeach
                                        </td>
                                        <td class="nw-num">{{ $stay->check_in->isoFormat('ddd D MMM YYYY') }}</td>
                                        <td class="nw-num">{{ $stay->check_out->isoFormat('ddd D MMM YYYY') }}</td>
                                        <td>
                                            {{-- A cancelled stay can still owe money, and
                                                 that is exactly the case a system without
                                                 a folio gets wrong. --}}
                                            <span class="nw-badge nw-badge--{{ $stay->status->color() }}">
                                                {{ $stay->status->label() }}
                                            </span>
                                        </td>
                                        <td class="nw-num">
                                            @if ($stay->total_amount === null)
                                                <span class="nw-warn">Not priced</span>
                                            @else
                                                {{ Money::format($stay->total_amount, $stay->currency) }}
                                            @endif
                                        </td>
                                        <td class="nw-num">{{ Money::format($stay->paid_amount, $stay->currency) }}</td>
                                        <td class="nw-num">
                                            @if ($stay->total_amount === null)
                                                <span class="nw-hint">—</span>
                                            @else
                                                {{ Money::format($stay->total_amount - $stay->paid_amount, $stay->currency) }}
                                            @endif
                                            <div>
                                                <span class="nw-badge nw-badge--{{ $stay->payment_status->color() }}">
                                                    {{ $stay->payment_status->label() }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="nw-num nw-hint">{{ $stay->reference }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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
