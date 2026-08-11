<?php

namespace App\Services\Inventory;

use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\InventoryBlock;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only thing in the codebase that writes inventory. Not a convention —
 * the models throw if written from anywhere else (see InventoryWriteGuard),
 * and an architecture test refuses to let query-builder writes to these
 * tables appear outside this namespace.
 *
 * The rule earns its keep later: an append-only ledger, allotments, channel
 * sync and offline replay are all changes *inside* this class if there is one
 * of it, and an excavation if inventory is written from five places.
 *
 * ## How two people cannot book the same last room
 *
 * Availability is a counter on the calendar row, and it moves by conditional
 * UPDATE:
 *
 *     UPDATE room_type_calendar_days
 *        SET units_sold = units_sold + :n
 *      WHERE room_type_id = :rt AND date = :d
 *        AND units_sold + units_blocked + :n <= COALESCE(units_total, :default)
 *
 * A single UPDATE is atomic, and Postgres re-evaluates the WHERE clause
 * against the *new* row version when a concurrent transaction has committed a
 * change to it. So of two transactions racing for the last unit, one updates
 * a row and the other updates none — and "affected 0 rows" is the sold-out
 * answer. The decision is the database's, not PHP's, which is what makes it
 * hold no matter which code path books.
 *
 * Nights are always processed in ascending date order, and lines in ascending
 * room type order, so two overlapping bookings take row locks in the same
 * sequence and cannot deadlock each other.
 */
class InventoryWriter
{
    private const COUNTER_SOLD = 'units_sold';

    private const COUNTER_BLOCKED = 'units_blocked';

    public function __construct(private readonly AvailabilityCalendar $calendar) {}

    /**
     * Create a stay and consume the inventory it needs.
     *
     * @throws InventoryUnavailableException when a night has too few units
     * @throws StayRuleViolationException when a restriction refuses it
     */
    public function book(BookingRequest $request): Reservation
    {
        $lines = $this->validatedLines($request);

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($request, $lines) {
            foreach ($lines as $line) {
                $this->calendar->assertStayRules($line->roomType, $line->checkIn, $line->checkOut);
            }

            $quotes = [];
            $currencies = [];

            foreach ($lines as $index => $line) {
                $quote = $this->calendar->quote($line->roomType, $line->checkIn, $line->checkOut, $line->quantity);
                $quotes[$index] = $quote;
                $currencies[] = $quote->currency;
            }

            $currencies = array_values(array_unique($currencies));

            if (count($currencies) > 1) {
                // A reservation carries one total in one currency. Mixing them
                // silently would produce a total that means nothing.
                throw new InvalidArgumentException(
                    'All room types on one reservation must share a currency; got '.implode(', ', $currencies).'.'
                );
            }

            // Consume first: a sold-out night should fail before any rows are
            // written, even though the transaction would roll them back anyway.
            foreach ($lines as $line) {
                foreach ($this->calendar->nights($line->checkIn, $line->checkOut) as $night) {
                    $this->consume($line->roomType, $night, $line->quantity, self::COUNTER_SOLD);
                }
            }

            $reservation = new Reservation([
                'reference' => $this->generateReference(),
                'listing_id' => $request->listing->id,
                'inquiry_id' => $request->inquiryId,
                'status' => $request->status,
                'source' => $request->source,
                'guest_name' => $request->guestName,
                'guest_email' => $request->guestEmail,
                'guest_phone' => $request->guestPhone,
                'check_in' => $this->earliest($lines),
                'check_out' => $this->latest($lines),
                'adults' => $request->adults,
                'children' => $request->children,
                'total_amount' => round(array_sum(array_map(fn ($quote) => $quote->total(), $quotes)), 2),
                'currency' => $currencies[0],
                'notes' => $request->notes,
                'created_by' => $request->createdBy,
            ]);
            $reservation->save();

            foreach ($lines as $index => $line) {
                $quote = $quotes[$index];

                $unit = new ReservationUnit([
                    'reservation_id' => $reservation->id,
                    'room_type_id' => $line->roomType->id,
                    'quantity' => $line->quantity,
                    'check_in' => $line->checkIn,
                    'check_out' => $line->checkOut,
                    'total_amount' => $quote->total(),
                    'currency' => $quote->currency,
                ]);
                $unit->save();

                foreach ($quote->nights as $night) {
                    (new ReservationNight([
                        'reservation_unit_id' => $unit->id,
                        'date' => $night->date,
                        'units' => $night->units,
                        'rate' => $night->rate,
                        'currency' => $quote->currency,
                    ]))->save();
                }
            }

            return $reservation->refresh();
        }));
    }

    /**
     * Release a stay's inventory and record why.
     *
     * Idempotent: cancelling an already-cancelled stay returns it untouched,
     * because a partner clicking a link twice must not give the units back
     * twice.
     *
     * `$late` defaults to asking the property's country how close to arrival
     * counts as late, rather than to a hardcoded window.
     */
    public function cancel(Reservation $reservation, ?string $reason = null, ?bool $late = null): Reservation
    {
        if (! $reservation->status->occupiesInventory()) {
            return $reservation;
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($reservation, $reason, $late) {
            $reservation->loadMissing('units.roomType', 'listing');

            foreach ($this->orderedUnits($reservation) as $unit) {
                $roomType = $unit->roomType;

                if ($roomType === null) {
                    continue;
                }

                foreach ($this->calendar->nights($unit->check_in, $unit->check_out) as $night) {
                    $this->release($roomType, $night, $unit->quantity, self::COUNTER_SOLD);
                }
            }

            $isLate = $late ?? ($reservation->listing !== null
                && CountrySettings::for($reservation->listing)->isLateCancellation($reservation->check_in));

            $reservation->status = $isLate ? StayStatus::CancelledLate : StayStatus::Cancelled;
            $reservation->cancelled_at = now();
            $reservation->cancellation_reason = $reason;
            $reservation->save();

            return $reservation;
        }));
    }

    /**
     * Move a stay along its lifecycle. Cancellation is not a transition —
     * it releases inventory, so it goes through cancel().
     */
    public function transition(Reservation $reservation, StayStatus $to): Reservation
    {
        if (! $to->occupiesInventory()) {
            throw new InvalidArgumentException(
                "Use cancel() to reach [{$to->value}]; it releases inventory and a plain status change would not."
            );
        }

        $allowed = self::allowedTransitions()[$reservation->status->value] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                "Cannot move a stay from [{$reservation->status->value}] to [{$to->value}]."
            );
        }

        return InventoryWriteGuard::allow(function () use ($reservation, $to) {
            $reservation->status = $to;
            $reservation->save();

            return $reservation;
        });
    }

    /**
     * Take units off sale. Consumes inventory exactly like a booking, through
     * its own counter so an occupancy view can still tell the two apart.
     *
     * @throws InventoryUnavailableException
     */
    public function block(BlockRequest $request): InventoryBlock
    {
        if ($request->units < 1) {
            throw new InvalidArgumentException('A block must cover at least one unit.');
        }

        if ($request->lastNight->lt($request->firstNight)) {
            throw new InvalidArgumentException('A block cannot end before it starts.');
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($request) {
            foreach ($this->blockNights($request->firstNight, $request->lastNight) as $night) {
                $this->consume($request->roomType, $night, $request->units, self::COUNTER_BLOCKED);
            }

            $block = new InventoryBlock([
                'room_type_id' => $request->roomType->id,
                'reason' => $request->reason,
                'units' => $request->units,
                'first_night' => $request->firstNight,
                'last_night' => $request->lastNight,
                'note' => $request->note,
                'created_by' => $request->createdBy,
            ]);
            $block->save();

            return $block;
        }));
    }

    /** Idempotent, for the same reason cancel() is. */
    public function releaseBlock(InventoryBlock $block): InventoryBlock
    {
        if ($block->released_at !== null) {
            return $block;
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($block) {
            $block->loadMissing('roomType');
            $roomType = $block->roomType;

            if ($roomType !== null) {
                foreach ($this->blockNights($block->first_night, $block->last_night) as $night) {
                    $this->release($roomType, $night, $block->units, self::COUNTER_BLOCKED);
                }
            }

            $block->released_at = now();
            $block->save();

            return $block;
        }));
    }

    /**
     * Write rates and restrictions across a date range — how a season is
     * expressed. Both ends inclusive, because these are nights.
     *
     * Only overrides and restrictions can be set here. The counters are
     * deliberately not writable: they are the outcome of bookings and blocks,
     * and letting a rate update reset them is precisely the class of bug the
     * single write path exists to prevent.
     *
     * @param  array<string, mixed>  $attributes  units_total, rate, min_stay,
     *                                            closed_to_arrival, closed_to_departure.
     *                                            A null value clears the override.
     * @return int number of nights written
     */
    public function setCalendar(RoomType $roomType, CarbonInterface $from, CarbonInterface $to, array $attributes): int
    {
        $allowed = ['units_total', 'rate', 'min_stay', 'closed_to_arrival', 'closed_to_departure'];
        $unknown = array_diff(array_keys($attributes), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'setCalendar() does not write ['.implode(', ', $unknown).']. Allowed: '.implode(', ', $allowed).'.'
            );
        }

        $first = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();

        if ($last->lt($first)) {
            throw new InvalidArgumentException('The calendar range cannot end before it starts.');
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($roomType, $first, $last, $attributes) {
            $written = 0;

            foreach ($this->blockNights($first, $last) as $night) {
                $this->ensureDay($roomType, $night);

                RoomTypeCalendarDay::query()
                    ->where('room_type_id', $roomType->id)
                    ->whereDate('date', $night->toDateString())
                    ->update($attributes);

                $written++;
            }

            return $written;
        }));
    }

    /**
     * The one statement that decides whether a booking fits. See the class
     * docblock for why this shape, and not a read followed by a write.
     *
     * @throws InventoryUnavailableException
     */
    private function consume(RoomType $roomType, Carbon $night, int $units, string $counter): void
    {
        $counter = $this->counterColumn($counter);
        $this->ensureDay($roomType, $night);

        $affected = DB::table('room_type_calendar_days')
            ->where('room_type_id', $roomType->id)
            ->whereDate('date', $night->toDateString())
            ->whereRaw(
                'units_sold + units_blocked + ? <= COALESCE(units_total, ?)',
                [$units, (int) $roomType->total_units]
            )
            ->increment($counter, $units, ['updated_at' => now()]);

        if ($affected === 0) {
            throw new InventoryUnavailableException($roomType->id, $night, $units);
        }
    }

    /**
     * Give units back. Guarded against going negative rather than trusting
     * the caller, and a mismatch is logged rather than thrown: refusing to
     * cancel a guest's stay because a counter is already wrong would make a
     * bookkeeping problem into an operational one.
     */
    private function release(RoomType $roomType, Carbon $night, int $units, string $counter): void
    {
        $counter = $this->counterColumn($counter);

        $affected = DB::table('room_type_calendar_days')
            ->where('room_type_id', $roomType->id)
            ->whereDate('date', $night->toDateString())
            ->where($counter, '>=', $units)
            ->decrement($counter, $units, ['updated_at' => now()]);

        if ($affected === 0) {
            Log::warning('InventoryWriter: nothing to release', [
                'room_type_id' => $roomType->id,
                'date' => $night->toDateString(),
                'counter' => $counter,
                'units' => $units,
            ]);
        }
    }

    /**
     * The calendar is sparse by design, so a night being booked may have no
     * row yet. Creating it with pure defaults changes no meaning — null
     * overrides still mean "follow the room type" — but gives the conditional
     * UPDATE a row to lock, which is what serialises two concurrent bookings.
     */
    private function ensureDay(RoomType $roomType, Carbon $night): void
    {
        DB::table('room_type_calendar_days')->insertOrIgnore([
            'room_type_id' => $roomType->id,
            'date' => $night->toDateString(),
            'units_total' => null,
            'units_sold' => 0,
            'units_blocked' => 0,
            'rate' => null,
            'min_stay' => null,
            'closed_to_arrival' => false,
            'closed_to_departure' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Allowlist, so no caller can ever aim these UPDATEs at another column. */
    private function counterColumn(string $counter): string
    {
        return match ($counter) {
            self::COUNTER_SOLD => self::COUNTER_SOLD,
            self::COUNTER_BLOCKED => self::COUNTER_BLOCKED,
            default => throw new InvalidArgumentException("[{$counter}] is not an inventory counter."),
        };
    }

    /**
     * Both ends inclusive — blocks and calendar ranges count nights, unlike a
     * stay, which counts an arrival and a departure.
     *
     * @return array<int, Carbon>
     */
    private function blockNights(CarbonInterface $first, CarbonInterface $last): array
    {
        return $this->calendar->nights($first, Carbon::parse($last)->startOfDay()->addDay());
    }

    /**
     * Validates a booking and puts its lines in a fixed order. The ordering
     * is not cosmetic: two overlapping bookings must take their row locks in
     * the same sequence or they can deadlock.
     *
     * @return array<int, BookingLine>
     */
    private function validatedLines(BookingRequest $request): array
    {
        if ($request->lines === []) {
            throw new InvalidArgumentException('A reservation needs at least one room type.');
        }

        foreach ($request->lines as $line) {
            if ($line->quantity < 1) {
                throw new InvalidArgumentException('A reservation line needs at least one unit.');
            }

            if ($line->nights() < 1) {
                throw new InvalidArgumentException('A reservation must cover at least one night.');
            }

            if ($line->roomType->listing_id !== $request->listing->id) {
                throw new InvalidArgumentException(
                    "Room type [{$line->roomType->id}] does not belong to listing [{$request->listing->id}]."
                );
            }
        }

        $lines = $request->lines;
        usort($lines, fn (BookingLine $a, BookingLine $b) => [$a->roomType->id, $a->checkIn->getTimestamp()]
            <=> [$b->roomType->id, $b->checkIn->getTimestamp()]);

        return $lines;
    }

    /**
     * Unit lines in the same order book() locks them, so cancelling while
     * another booking is in flight cannot deadlock against it either.
     *
     * @return array<int, ReservationUnit>
     */
    private function orderedUnits(Reservation $reservation): array
    {
        return $reservation->units
            ->sortBy([['room_type_id', 'asc'], ['check_in', 'asc']])
            ->values()
            ->all();
    }

    /** @param  array<int, BookingLine>  $lines */
    private function earliest(array $lines): Carbon
    {
        return collect($lines)->map(fn (BookingLine $line) => $line->checkIn)->min();
    }

    /** @param  array<int, BookingLine>  $lines */
    private function latest(array $lines): Carbon
    {
        return collect($lines)->map(fn (BookingLine $line) => $line->checkOut)->max();
    }

    private function generateReference(): string
    {
        do {
            $reference = 'NW-'.strtoupper(Str::random(8));
        } while (Reservation::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return array<string, array<int, StayStatus>>
     */
    public static function allowedTransitions(): array
    {
        return [
            StayStatus::Provisional->value => [StayStatus::Confirmed, StayStatus::DueIn],
            StayStatus::Confirmed->value => [StayStatus::DueIn, StayStatus::InHouse, StayStatus::NoShow],
            StayStatus::DueIn->value => [StayStatus::InHouse, StayStatus::NoShow],
            StayStatus::InHouse->value => [StayStatus::CheckedOut],
            StayStatus::CheckedOut->value => [],
            StayStatus::NoShow->value => [],
            StayStatus::Cancelled->value => [],
            StayStatus::CancelledLate->value => [],
        ];
    }
}
