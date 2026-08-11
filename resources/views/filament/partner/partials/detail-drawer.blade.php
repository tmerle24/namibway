@php
    use App\Filament\Partner\Resources\CustomerResource;
    use App\Support\Money;

    /** @var \App\Models\Reservation|null $reservation */
    /** @var \App\Models\InventoryBlock|null $block */
    $reservation = $reservation ?? null;
    $block = $block ?? null;
@endphp

{{--
    The detail both lodge screens open — a stay from a bar or a row, a block
    from a bar.

    The actions offered here are the ones the domain says are legal right now:
    the status buttons come from InventoryWriter::allowedTransitions(), so a
    screen can never offer a move the writer would refuse. Cancelling is not
    among them on purpose — it gives rooms back, so it goes through cancel()
    and asks for a reason.
--}}
@if ($reservation || $block)
    <div
        class="nw-drawer"
        role="dialog"
        aria-modal="true"
        wire:click.self="closeReservation"
        x-data
        x-on:keydown.escape.window="$wire.closeReservation()"
    >
        <div class="nw-drawer__panel">
            @if ($reservation)
                <div class="nw-drawer__head">
                    <div>
                        <div class="nw-drawer__title">{{ $reservation->guest_name }}</div>
                        <div class="nw-hint">
                            {{ $reservation->reference }} ·
                            <span class="nw-badge nw-badge--{{ $reservation->status->color() }}">
                                {{ $reservation->status->label() }}
                            </span>
                        </div>
                    </div>

                    <button type="button" class="nw-btn" wire:click="closeReservation">Close</button>
                </div>

                <dl class="nw-facts">
                    <dt>Arrival</dt>
                    <dd>{{ $reservation->check_in->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Departure</dt>
                    <dd>{{ $reservation->check_out->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Nights</dt>
                    <dd class="nw-num">{{ $reservation->nights() }}</dd>

                    <dt>Guests</dt>
                    <dd class="nw-num">
                        {{ $reservation->adults }} {{ Str::plural('adult', $reservation->adults) }}@if ($reservation->children), {{ $reservation->children }} {{ Str::plural('child', $reservation->children) }}@endif
                    </dd>

                    <dt>Booked via</dt>
                    <dd>{{ $reservation->source->label() }}</dd>

                    @if ($reservation->guest_email)
                        <dt>Email</dt>
                        <dd>{{ $reservation->guest_email }}</dd>
                    @endif

                    @if ($reservation->guest_phone)
                        <dt>Phone</dt>
                        <dd>{{ $reservation->guest_phone }}</dd>
                    @endif

                    @if ($reservation->total_amount !== null)
                        @php($stayCharges = $reservation->charges)

                        {{-- The total is what the guest pays. Where anything was
                             added on top of the stay, the parts are named above it,
                             because "what is this 15%?" is the question a desk is
                             asked and an unexplained total is the reason invoices
                             get disputed. --}}
                        @if ($stayCharges->isNotEmpty())
                            <dt>Stay</dt>
                            <dd class="nw-num">{{ Money::format($reservation->stayAmount(), $reservation->currency) }}</dd>

                            @foreach ($stayCharges as $charge)
                                <dt>{{ $charge->label() }}</dt>
                                <dd class="nw-num">
                                    {{ Money::format($charge->amount, $charge->currency) }}
                                    @if ($charge->is_included)
                                        <span class="nw-hint">included</span>
                                    @endif
                                </dd>
                            @endforeach
                        @endif

                        <dt>Total</dt>
                        <dd class="nw-num">
                            {{ Money::format($reservation->total_amount, $reservation->currency) }}
                            @if ($reservation->priceWasOverridden())
                                <span class="nw-hint">
                                    (calendar said {{ Money::format($reservation->quoted_amount, $reservation->currency) }})
                                </span>
                            @endif
                        </dd>
                    @endif

                    @if ($reservation->priceWasOverridden() && filled($reservation->price_override_reason))
                        <dt>Price changed</dt>
                        <dd>{{ $reservation->price_override_reason }}</dd>
                    @endif

                    @if ($reservation->cancelled_at)
                        <dt>Cancelled</dt>
                        <dd>
                            {{ $reservation->cancelled_at->isoFormat('ddd D MMM YYYY') }}
                            @if (filled($reservation->cancellation_reason))
                                — {{ $reservation->cancellation_reason }}
                            @endif
                        </dd>
                    @endif
                </dl>

                {{-- Nothing to offer on a stay that is over or cancelled, and an
                     empty toolbar reads better than disabled buttons. --}}
                @php($transitions = $this->availableTransitions())

                @if ($transitions !== [] || $reservation->status->occupiesInventory())
                    <div class="nw-drawer__actions nw-noprint">
                        @foreach ($transitions as $transition)
                            <button
                                type="button"
                                class="nw-btn nw-btn--primary"
                                wire:click="transitionStay('{{ $transition->value }}')"
                                wire:loading.attr="disabled"
                            >
                                {{ $transition->label() }}
                            </button>
                        @endforeach

                        @if ($reservation->status->occupiesInventory())
                            {{ $this->cancelStayAction }}
                        @endif
                    </div>
                @endif

                {{--
                    More people than the room sleeps, and what the desk said it
                    was doing about it. Kept beside the booking rather than in
                    the running log because whoever makes the room up has to
                    see it without reading a conversation.
                --}}
                @if (filled($reservation->over_capacity_note))
                    <div class="nw-subhead">Extra bed</div>
                    <p class="nw-warn">{{ $reservation->over_capacity_note }}</p>
                @endif

                @if (filled($reservation->notes))
                    <div class="nw-subhead">Booking note</div>
                    <p class="nw-hint">{{ $reservation->notes }}</p>
                @endif

                {{-- The running log, which is a different thing from the note
                     the booking was taken with: these have an author and a
                     time, and they keep arriving while the guest is here. --}}
                @php($stayNotes = $reservation->noteLog()->limit(20)->get())

                <div class="nw-subhead">Notes</div>

                @if ($stayNotes->isEmpty())
                    <p class="nw-hint">Nothing written yet.</p>
                @else
                    @foreach ($stayNotes as $note)
                        <p class="nw-hint">
                            <strong>{{ $note->author_name }}</strong>,
                            {{ $note->created_at?->isoFormat('D MMM, HH:mm') }} — {{ $note->body }}
                        </p>
                    @endforeach
                @endif

                <div class="nw-drawer__actions nw-noprint">
                    {{ $this->addStayNoteAction }}

                    @if ($reservation->customer_id !== null)
                        {{-- The other way round through the same data: from this
                             stay to everything else this person has booked. --}}
                        <a class="nw-btn" href="{{ CustomerResource::getUrl('view', ['record' => $reservation->customer_id]) }}">
                            {{ $reservation->customer?->name ?? 'Customer' }} &rarr;
                        </a>
                    @endif
                </div>

                <div class="nw-subhead">Rooms</div>

                <table class="nw-table">
                    <thead>
                        <tr>
                            <th>Room type</th>
                            <th>Sold as</th>
                            <th>Occupants</th>
                            <th>Units</th>
                            <th>Dates</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservation->units as $unit)
                            <tr>
                                <td>
                                    {{ $unit->bookableUnit?->name ?? '—' }}
                                    {{--
                                        Which departure, frozen onto the stay
                                        for the same reason the plan's name is:
                                        renaming "Morning departure" in March
                                        must not change what February sold.
                                    --}}
                                    @if (filled($unit->slot_label))
                                        <div class="nw-hint">{{ $unit->slot_label }}</div>
                                    @endif
                                </td>
                                {{--
                                    Read from the stay, not from the rate plan:
                                    what this room was sold as is a fact about
                                    February, and the plan may have been renamed
                                    since. See the reservation_units migration.
                                --}}
                                <td>{{ $unit->soldAs() ?? '—' }}</td>
                                <td>
                                    @forelse ($unit->guests as $guest)
                                        {{ $guest->count }} {{ $guest->category?->name ?? 'guest' }}@if (! $loop->last), @endif
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="nw-num">{{ $unit->quantity }}</td>
                                <td class="nw-num">
                                    {{ $unit->check_in->isoFormat('D MMM') }} – {{ $unit->check_out->isoFormat('D MMM') }}
                                </td>
                                <td class="nw-num">{{ Money::format($unit->total_amount, $unit->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{--
                    The per-night breakdown is the answer to "why does this stay
                    cost that", and it is also where a season boundary becomes
                    visible: the rate simply changes on the night it changes.
                --}}
                <div class="nw-subhead">Per night</div>

                <table class="nw-table">
                    <thead>
                        <tr>
                            <th>Night</th>
                            <th>Room type</th>
                            <th>Units</th>
                            <th>Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservation->units as $unit)
                            @foreach ($unit->nights->sortBy('date') as $night)
                                <tr>
                                    <td class="nw-num">{{ $night->date->isoFormat('ddd D MMM') }}</td>
                                    <td>{{ $unit->bookableUnit?->name ?? '—' }}</td>
                                    <td class="nw-num">{{ $night->units }}</td>
                                    <td class="nw-num">{{ Money::format($night->rate, $night->currency) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="nw-drawer__head">
                    <div>
                        <div class="nw-drawer__title">{{ $block->reason->label() }}</div>
                        <div class="nw-hint">Rooms off sale — not a booking</div>
                    </div>

                    <button type="button" class="nw-btn" wire:click="closeReservation">Close</button>
                </div>

                <dl class="nw-facts">
                    <dt>Room type</dt>
                    <dd>{{ $block->bookableUnit?->name ?? '—' }}</dd>

                    <dt>Units</dt>
                    <dd class="nw-num">{{ $block->units }}</dd>

                    <dt>First night</dt>
                    <dd>{{ $block->first_night->isoFormat('ddd D MMM YYYY') }}</dd>

                    <dt>Last night</dt>
                    <dd>{{ $block->last_night->isoFormat('ddd D MMM YYYY') }}</dd>
                </dl>

                @if (filled($block->note))
                    <div class="nw-subhead">Note</div>
                    <p class="nw-hint">{{ $block->note }}</p>
                @endif

                @if ($block->released_at === null)
                    <div class="nw-drawer__actions nw-noprint">
                        {{ $this->editBlockAction }}
                        {{ $this->releaseBlockAction }}
                    </div>
                @else
                    <p class="nw-hint">
                        Back on sale since {{ $block->released_at->isoFormat('D MMM YYYY') }}.
                    </p>
                @endif
            @endif
        </div>
    </div>
@endif
