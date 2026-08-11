<?php

namespace App\Services\Inventory;

use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\InventoryBlock;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\Reservation;
use App\Models\ReservationGuest;
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
            // Resolved once per line and reused for rules, pricing and the
            // recorded line, so a stay cannot be checked against one plan and
            // priced under another.
            $fallback = RatePlan::defaultFor($request->listing);
            $plans = [];

            foreach ($lines as $index => $line) {
                $plans[$index] = $line->ratePlan ?? $fallback;
            }

            foreach ($lines as $index => $line) {
                $this->calendar->assertStayRules($line->roomType, $line->checkIn, $line->checkOut, $plans[$index]);
            }

            $quotes = [];
            $currencies = [];

            foreach ($lines as $index => $line) {
                $quote = $this->calendar->quote(
                    $line->roomType,
                    $line->checkIn,
                    $line->checkOut,
                    $line->quantity,
                    $plans[$index],
                    $line->occupancy,
                );
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

            // What the calendar says, priced night by night, so a stay crossing
            // a season boundary needs no special case.
            $quoted = round(array_sum(array_map(fn ($quote) => $quote->total(), $quotes)), 2);
            $charged = $request->totalOverride === null ? $quoted : round($request->totalOverride, 2);
            $overridden = abs($charged - $quoted) >= 0.01;

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
                'total_amount' => $charged,
                'quoted_amount' => $quoted,
                // Only recorded when the two actually differ: stamping a
                // reason and a user onto every booking would make the column
                // useless for finding the ones somebody changed.
                'price_override_reason' => $overridden ? $request->overrideReason : null,
                'price_overridden_by' => $overridden ? $request->createdBy : null,
                'price_overridden_at' => $overridden ? now() : null,
                'currency' => $currencies[0],
                'notes' => $request->notes,
                'created_by' => $request->createdBy,
            ]);
            $reservation->save();

            foreach ($lines as $index => $line) {
                $quote = $quotes[$index];

                // Lines and nights stay at the calendar's price even when the
                // header was overridden. Spreading an override across them
                // would invent a per-night rate nobody chose, and the
                // difference between the two totals is the record of what
                // actually happened.
                $unit = new ReservationUnit([
                    'reservation_id' => $reservation->id,
                    'room_type_id' => $line->roomType->id,
                    'rate_plan_id' => $plans[$index]?->id,
                    'quantity' => $line->quantity,
                    'check_in' => $line->checkIn,
                    'check_out' => $line->checkOut,
                    'total_amount' => $quote->total(),
                    'currency' => $quote->currency,
                ]);
                $unit->save();

                // Who the price was computed from, kept beside the price. A
                // stay whose occupancy is only in the total is one nobody can
                // check afterwards.
                foreach ($line->occupancy?->counts ?? [] as $categoryId => $count) {
                    (new ReservationGuest([
                        'reservation_unit_id' => $unit->id,
                        'guest_category_id' => $categoryId,
                        'count' => $count,
                    ]))->save();
                }

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

    /**
     * Change a block's dates, size or reason.
     *
     * Implemented as release-then-consume inside one transaction rather than
     * as an UPDATE, because a block's shape lives in two places at once: the
     * row, and the `units_blocked` counters on every night it covers. Editing
     * the row alone would leave the counters describing the old block for
     * ever. Doing it in this order also means a widened block that no longer
     * fits rolls back to exactly the block that was there before, rather than
     * leaving the property with no block at all.
     *
     * @throws InventoryUnavailableException when the new shape does not fit
     */
    public function updateBlock(InventoryBlock $block, BlockRequest $request): InventoryBlock
    {
        if ($block->released_at !== null) {
            throw new InvalidArgumentException('A released block cannot be edited; create a new one.');
        }

        if ($request->units < 1) {
            throw new InvalidArgumentException('A block must cover at least one unit.');
        }

        if ($request->lastNight->lt($request->firstNight)) {
            throw new InvalidArgumentException('A block cannot end before it starts.');
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($block, $request) {
            $block->loadMissing('roomType');
            $previous = $block->roomType;

            if ($previous !== null) {
                foreach ($this->blockNights($block->first_night, $block->last_night) as $night) {
                    $this->release($previous, $night, $block->units, self::COUNTER_BLOCKED);
                }
            }

            foreach ($this->blockNights($request->firstNight, $request->lastNight) as $night) {
                $this->consume($request->roomType, $night, $request->units, self::COUNTER_BLOCKED);
            }

            $block->fill([
                'room_type_id' => $request->roomType->id,
                'reason' => $request->reason,
                'units' => $request->units,
                'first_night' => $request->firstNight,
                'last_night' => $request->lastNight,
                'note' => $request->note,
            ]);
            $block->save();

            return $block->refresh();
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
     * Write to the property's **default** rate plan, and to its inventory,
     * from one call.
     *
     * Most callers genuinely mean this: a seeder, a demo, a lodge that sells
     * one product and has never heard the words "rate plan". They should not
     * have to name one, and this resolves it — creating the default plan if
     * the property has none, because a rate has to be written somewhere.
     *
     * `units_total` goes to the inventory calendar and everything else to the
     * rate plan's, which is the split this method exists to hide. Anything
     * that cares which plan it is writing to should call setRates() and
     * setInventory() directly and say so.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>|null  $weekdays
     * @return int number of nights written
     */
    public function setCalendar(
        RoomType $roomType,
        CarbonInterface $from,
        CarbonInterface $to,
        array $attributes,
        ?array $weekdays = null,
    ): int {
        $inventory = array_intersect_key($attributes, ['units_total' => true]);
        $rates = array_diff_key($attributes, $inventory);
        $written = 0;

        if ($inventory !== []) {
            $written = $this->setInventory($roomType, $from, $to, $inventory, $weekdays);
        }

        if ($rates !== []) {
            $listing = $roomType->listing;

            if ($listing === null) {
                throw new InvalidArgumentException(
                    "Room type [{$roomType->id}] has no listing, so there is no rate plan to write to."
                );
            }

            $written = max($written, $this->setRates(
                RatePlan::ensureDefaultFor($listing),
                $roomType,
                $from,
                $to,
                $rates,
                $weekdays,
            ));
        }

        return $written;
    }

    /**
     * Write a rate plan's rates and restrictions across a date range — how a
     * season is expressed. Both ends inclusive, because these are nights.
     *
     * `$weekdays` restricts the write to certain days of the week, as ISO-8601
     * numbers (1 = Monday … 7 = Sunday). Null or empty means every night. This
     * is not a convenience: "weekends cost more" is how rates are actually
     * maintained, and every channel manager's bulk update carries it, so
     * leaving it out would push a lodge into editing cells one at a time.
     *
     * Written in bulk rather than night by night. A season is easily half a
     * year, and a per-night round trip is hundreds of queries over the kind of
     * connection these properties have.
     *
     * Counters are deliberately unreachable from here — they belong to the
     * room type, not to a product sold under it, and letting a rate update
     * touch them is precisely the class of bug the single write path exists to
     * prevent. Capacity goes through setInventory().
     *
     * @param  array<string, mixed>  $attributes  rate, min_stay, closed_to_arrival,
     *                                            closed_to_departure. A null value
     *                                            clears the override.
     * @param  array<int, int>|null  $weekdays  ISO-8601 day numbers, or null for every night
     * @return int number of nights written
     */
    public function setRates(
        RatePlan $ratePlan,
        RoomType $roomType,
        CarbonInterface $from,
        CarbonInterface $to,
        array $attributes,
        ?array $weekdays = null,
    ): int {
        $this->assertOnly($attributes, ['rate', 'min_stay', 'closed_to_arrival', 'closed_to_departure'], 'setRates()');

        if ($roomType->listing_id !== $ratePlan->listing_id) {
            throw new InvalidArgumentException(
                "Rate plan [{$ratePlan->id}] and room type [{$roomType->id}] belong to different properties."
            );
        }

        $dates = $this->rangeDates($from, $to, $weekdays);

        if ($dates === [] || $attributes === []) {
            return 0;
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($ratePlan, $roomType, $dates, $attributes) {
            foreach (array_chunk($dates, 500) as $chunk) {
                $this->ensureRateDays($ratePlan, $roomType, $chunk);

                RatePlanDay::query()
                    ->where('rate_plan_id', $ratePlan->id)
                    ->where('room_type_id', $roomType->id)
                    ->whereIn('date', $chunk)
                    ->update($attributes);
            }

            return count($dates);
        }));
    }

    /**
     * Write how many units of a room type are on sale across a date range.
     *
     * Separate from setRates() because inventory is not a property of a rate
     * plan: a room is sold once however many products it is offered under. See
     * BOOKING_SYSTEM.md.
     *
     * @param  array<string, mixed>  $attributes  units_total. Null clears the override,
     *                                            putting the night back on the room
     *                                            type's own total.
     * @param  array<int, int>|null  $weekdays  ISO-8601 day numbers, or null for every night
     * @return int number of nights written
     */
    public function setInventory(
        RoomType $roomType,
        CarbonInterface $from,
        CarbonInterface $to,
        array $attributes,
        ?array $weekdays = null,
    ): int {
        $this->assertOnly($attributes, ['units_total'], 'setInventory()');

        $dates = $this->rangeDates($from, $to, $weekdays);

        if ($dates === [] || $attributes === []) {
            return 0;
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($roomType, $dates, $attributes) {
            foreach (array_chunk($dates, 500) as $chunk) {
                $this->ensureDays($roomType, $chunk);

                RoomTypeCalendarDay::query()
                    ->where('room_type_id', $roomType->id)
                    ->whereIn('date', $chunk)
                    ->update($attributes);
            }

            return count($dates);
        }));
    }

    /**
     * Write what a room costs for one guest, for two, for three — across a
     * date range. Both ends inclusive; these are nights.
     *
     * `$amounts` is guest count => amount, and a null amount removes that
     * guest count from those nights. Removing matters: a lodge that stops
     * selling a chalet to four people needs the four-guest price to *go*, not
     * to sit there being sold, and there is no other way to say so.
     *
     * Guest counts not mentioned are left alone, which is the same rule as
     * everywhere else in the bulk editor — somebody changing the two-guest
     * price must not silently drop the three-guest one.
     *
     * @param  array<int, float|null>  $amounts  guests => amount, or null to remove
     * @param  array<int, int>|null  $weekdays  ISO-8601 day numbers, or null for every night
     * @return int number of nights written
     */
    public function setGuestAmounts(
        RatePlan $ratePlan,
        RoomType $roomType,
        CarbonInterface $from,
        CarbonInterface $to,
        array $amounts,
        ?array $weekdays = null,
    ): int {
        if ($roomType->listing_id !== $ratePlan->listing_id) {
            throw new InvalidArgumentException(
                "Rate plan [{$ratePlan->id}] and room type [{$roomType->id}] belong to different properties."
            );
        }

        foreach (array_keys($amounts) as $guests) {
            if ($guests < 1) {
                throw new InvalidArgumentException("A guest count has to be at least 1; got [{$guests}].");
            }
        }

        $dates = $this->rangeDates($from, $to, $weekdays);

        if ($dates === [] || $amounts === []) {
            return 0;
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($ratePlan, $roomType, $dates, $amounts) {
            foreach (array_chunk($dates, 500) as $chunk) {
                foreach ($amounts as $guests => $amount) {
                    if ($amount === null) {
                        RatePlanGuestAmount::query()
                            ->where('rate_plan_id', $ratePlan->id)
                            ->where('room_type_id', $roomType->id)
                            ->where('guests', $guests)
                            ->whereIn('date', $chunk)
                            ->delete();

                        continue;
                    }

                    $rows = [];
                    $now = now();

                    foreach ($chunk as $date) {
                        $rows[] = [
                            'rate_plan_id' => $ratePlan->id,
                            'room_type_id' => $roomType->id,
                            'date' => $date,
                            'guests' => $guests,
                            'amount' => round($amount, 2),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    RatePlanGuestAmount::query()->upsert(
                        $rows,
                        ['rate_plan_id', 'room_type_id', 'date', 'guests'],
                        ['amount', 'updated_at'],
                    );
                }
            }

            return count($dates);
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $allowed
     */
    private function assertOnly(array $attributes, array $allowed, string $method): void
    {
        $unknown = array_diff(array_keys($attributes), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                $method.' does not write ['.implode(', ', $unknown).']. Allowed: '.implode(', ', $allowed).'.'
            );
        }
    }

    /**
     * The nights a bulk write covers, as Y-m-d strings.
     *
     * @param  array<int, int>|null  $weekdays
     * @return array<int, string>
     */
    private function rangeDates(CarbonInterface $from, CarbonInterface $to, ?array $weekdays): array
    {
        $first = Carbon::parse($from)->startOfDay();
        $last = Carbon::parse($to)->startOfDay();

        if ($last->lt($first)) {
            throw new InvalidArgumentException('The calendar range cannot end before it starts.');
        }

        return $this->datesToWrite($first, $last, $weekdays);
    }

    /**
     * Erase a demo property's whole book — every stay, block and calendar
     * night under it.
     *
     * This exists for one caller: `booking:demo-tenant` rebuilding a sandbox a
     * prospect has played with. It is the only destructive operation in the
     * inventory domain, so it refuses to run against anything but a demo
     * tenant, and the check is on the partner rather than on an argument the
     * caller passes — a flag a caller sets is a flag a caller can get wrong.
     *
     * @return array<string, int> rows removed, per kind
     */
    public function purgeProperty(Listing $listing): array
    {
        $listing->loadMissing('partner');

        if ($listing->partner?->is_demo !== true) {
            throw new InvalidArgumentException(
                "Refusing to purge inventory for listing [{$listing->id}]: it does not belong to a demo tenant."
            );
        }

        return InventoryWriteGuard::allow(fn () => DB::transaction(function () use ($listing) {
            $roomTypeIds = $listing->roomTypes()->pluck('id')->all();

            $reservationIds = Reservation::query()->where('listing_id', $listing->id)->pluck('id')->all();
            $unitIds = $reservationIds === []
                ? []
                : ReservationUnit::query()->whereIn('reservation_id', $reservationIds)->pluck('id')->all();

            // Children first: reservation_units restricts deletion of the room
            // types the demo rebuild is about to replace.
            $nights = $unitIds === [] ? 0 : ReservationNight::query()->whereIn('reservation_unit_id', $unitIds)->delete();

            if ($unitIds !== []) {
                ReservationGuest::query()->whereIn('reservation_unit_id', $unitIds)->delete();
            }

            $units = $unitIds === [] ? 0 : ReservationUnit::query()->whereIn('id', $unitIds)->delete();
            $reservations = $reservationIds === [] ? 0 : Reservation::query()->whereIn('id', $reservationIds)->delete();

            $blocks = $roomTypeIds === [] ? 0 : InventoryBlock::query()->whereIn('room_type_id', $roomTypeIds)->delete();
            $days = $roomTypeIds === [] ? 0 : RoomTypeCalendarDay::query()->whereIn('room_type_id', $roomTypeIds)->delete();

            // Rates too: a room type survives a purge, so leaving its priced
            // nights behind would show a rebuilt demo last month's rates.
            $rateDays = $roomTypeIds === [] ? 0 : RatePlanDay::query()->whereIn('room_type_id', $roomTypeIds)->delete();

            if ($roomTypeIds !== []) {
                RatePlanGuestAmount::query()->whereIn('room_type_id', $roomTypeIds)->delete();
            }

            return [
                'reservations' => $reservations,
                'reservation_units' => $units,
                'reservation_nights' => $nights,
                'blocks' => $blocks,
                'calendar_days' => $days,
                'rate_plan_days' => $rateDays,
            ];
        }));
    }

    /**
     * The nights a bulk calendar write covers, as Y-m-d strings.
     *
     * @param  array<int, int>|null  $weekdays
     * @return array<int, string>
     */
    private function datesToWrite(Carbon $first, Carbon $last, ?array $weekdays): array
    {
        $wanted = array_values(array_unique(array_map('intval', $weekdays ?? [])));

        foreach ($wanted as $day) {
            if ($day < 1 || $day > 7) {
                throw new InvalidArgumentException("[{$day}] is not an ISO-8601 weekday; use 1 (Monday) to 7 (Sunday).");
            }
        }

        $dates = [];

        foreach ($this->blockNights($first, $last) as $night) {
            if ($wanted !== [] && ! in_array((int) $night->isoWeekday(), $wanted, true)) {
                continue;
            }

            $dates[] = $night->toDateString();
        }

        return $dates;
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ensureDay() for many nights at once, for the bulk calendar write.
     * insertOrIgnore against the (room_type_id, date) unique index, so nights
     * that already exist keep their counters — this may never reset what a
     * booking has consumed.
     *
     * @param  array<int, string>  $dates
     */
    private function ensureDays(RoomType $roomType, array $dates): void
    {
        $now = now();
        $rows = [];

        foreach ($dates as $date) {
            $rows[] = [
                'room_type_id' => $roomType->id,
                'date' => $date,
                'units_total' => null,
                'units_sold' => 0,
                'units_blocked' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('room_type_calendar_days')->insertOrIgnore($rows);
    }

    /**
     * ensureDays() for the rate calendar. Same reason: a night with no row
     * cannot be updated, and creating one with pure nulls changes no meaning
     * because null still reads as "follow the room type".
     *
     * @param  array<int, string>  $dates
     */
    private function ensureRateDays(RatePlan $ratePlan, RoomType $roomType, array $dates): void
    {
        $now = now();
        $rows = [];

        foreach ($dates as $date) {
            $rows[] = [
                'rate_plan_id' => $ratePlan->id,
                'room_type_id' => $roomType->id,
                'date' => $date,
                'rate' => null,
                'min_stay' => null,
                'closed_to_arrival' => false,
                'closed_to_departure' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('rate_plan_days')->insertOrIgnore($rows);
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
