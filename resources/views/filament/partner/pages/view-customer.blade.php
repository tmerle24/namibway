@php
    /** @var \App\Models\Customer $customer */
    $customer = $this->getRecord();
    $stays = $this->stays();
    $notes = $this->noteLog();
    $nights = $stays->sum(fn ($stay) => $stay->nights());
@endphp

<x-filament-panels::page>
    <div class="nw-customer">
        <x-filament::section>
            <x-slot name="heading">{{ $customer->name }}</x-slot>

            <x-slot name="description">
                @if ($customer->user_id !== null)
                    Has a NamibWay account, so they stay the same person after an email change.
                @else
                    No NamibWay account — recognised by email address.
                @endif
            </x-slot>

            <dl class="nw-customer__pairs">
                <div>
                    <dt>Email</dt>
                    <dd>{{ $customer->email ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Phone</dt>
                    <dd>{{ $customer->phone ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Stays</dt>
                    <dd>{{ $stays->count() }}</dd>
                </div>
                <div>
                    <dt>Nights</dt>
                    <dd>{{ $nights }}</dd>
                </div>
                <div>
                    <dt>Known since</dt>
                    <dd>{{ $customer->created_at?->isoFormat('D MMM YYYY') ?? '—' }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Stays</x-slot>

            <x-slot name="description">
                Newest first. The name and address on each one are what that booking was taken
                under, which is why correcting the customer does not change them.
            </x-slot>

            @if ($stays->isEmpty())
                <p class="nw-customer__empty">Nothing booked yet.</p>
            @else
                <div class="nw-customer__scroll">
                    <table class="nw-customer__table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Property</th>
                                <th>Rooms</th>
                                <th>Arrival</th>
                                <th>Nights</th>
                                <th>Status</th>
                                <th class="nw-customer__right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stays as $stay)
                                <tr>
                                    <td class="nw-customer__mono">{{ $stay->reference }}</td>
                                    <td>{{ $stay->listing?->name ?? '—' }}</td>
                                    <td>{{ $stay->units->map(fn ($unit) => $unit->bookableUnit?->name)->filter()->implode(', ') ?: '—' }}</td>
                                    <td>{{ $stay->check_in->isoFormat('D MMM YYYY') }}</td>
                                    <td>{{ $stay->nights() }}</td>
                                    <td>{{ $stay->status->label() }}</td>
                                    <td class="nw-customer__right">
                                        {{ $stay->total_amount === null ? '—' : $stay->currency . ' ' . number_format($stay->total_amount, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Notes</x-slot>

            <x-slot name="description">
                What staff have written about this person. Each one keeps its author and its date,
                because a note nobody can attribute is one nobody trusts.
            </x-slot>

            @if ($notes->isEmpty())
                <p class="nw-customer__empty">Nothing written yet.</p>
            @else
                <ul class="nw-customer__notes">
                    @foreach ($notes as $note)
                        <li class="nw-customer__note">
                            <p class="nw-customer__meta">
                                {{ $note->author_name }} &middot;
                                {{ $note->created_at?->isoFormat('D MMM YYYY, HH:mm') }}
                            </p>
                            <p class="nw-customer__body">{{ $note->body }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <style>
            .nw-customer {
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
            }

            .nw-customer__pairs {
                display: grid;
                gap: 0.6rem;
                grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
            }

            .nw-customer__pairs dt {
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: rgb(113 113 122);
            }

            .nw-customer__pairs dd {
                margin-top: 0.15rem;
                font-size: 0.9375rem;
                font-weight: 600;
                color: rgb(24 24 27);
                overflow-wrap: anywhere;
            }

            .nw-customer__scroll {
                overflow-x: auto;
            }

            .nw-customer__table {
                width: 100%;
                border-collapse: collapse;
                font-size: 0.8125rem;
                white-space: nowrap;
            }

            .nw-customer__table th {
                text-align: left;
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: rgb(113 113 122);
                padding: 0 0.6rem 0.35rem 0;
            }

            .nw-customer__table td {
                padding: 0.4rem 0.6rem 0.4rem 0;
                border-top: 1px solid rgb(228 228 231);
                color: rgb(39 39 42);
            }

            .nw-customer__right {
                text-align: right;
                padding-right: 0 !important;
            }

            .nw-customer__mono {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            }

            .nw-customer__notes {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .nw-customer__note {
                padding: 0.6rem 0.75rem;
                border-radius: 0.5rem;
                border: 1px solid rgb(228 228 231);
                background-color: rgb(250 250 250);
            }

            .nw-customer__meta {
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: rgb(113 113 122);
            }

            .nw-customer__body {
                margin-top: 0.2rem;
                font-size: 0.875rem;
                line-height: 1.45;
                color: rgb(39 39 42);
                white-space: pre-line;
            }

            .nw-customer__empty {
                font-size: 0.8125rem;
                color: rgb(113 113 122);
            }

            .dark .nw-customer__pairs dd,
            .dark .nw-customer__table td,
            .dark .nw-customer__body {
                color: rgb(244 244 245);
            }

            .dark .nw-customer__table td {
                border-color: rgb(63 63 70);
            }

            .dark .nw-customer__note {
                border-color: rgb(63 63 70);
                background-color: rgb(39 39 42);
            }
        </style>
    </div>
</x-filament-panels::page>
