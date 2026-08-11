<?php

namespace App\Services\Inventory;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Exceptions\Pricing\PromotionUnavailableException;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\ManualBookingLinePreview;
use App\Services\Inventory\DTOs\ManualBookingPreview;
use App\Services\Inventory\DTOs\ResolvedRoomLine;
use App\Services\Pricing\ChargeableStay;
use App\Services\Pricing\ChargeCalculator;
use App\Services\Pricing\Occupancy;
use App\Services\Pricing\PromotionFinder;
use App\Services\Pricing\StaySummary;
use App\Support\CountrySettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A walk-in or a telephone booking, entered by whoever is standing at the
 * desk.
 *
 * The mechanics are the writer's, unchanged — a booking typed in at reception
 * consumes inventory exactly like one that came through the website, which is
 * the whole reason there is one write path. What lives here is the part the
 * writer deliberately does not do: turning half-filled form input into a
 * priced, checked proposal, and saying in plain words why one cannot be
 * taken.
 *
 * `preview()` never writes. It exists because the writer's refusal arrives
 * after the save, and a front desk needs to know which room type is short on
 * which night while the guest is still standing there. The writer still
 * refuses independently — two people can be typing at once, and this check
 * cannot see the other one.
 */
class ManualBooking
{
    public function __construct(
        private readonly AvailabilityCalendar $calendar,
        private readonly InventoryWriter $writer,
    ) {}

    /**
     * Price and check a proposed stay without writing anything.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function preview(
        Listing $listing,
        ?CarbonInterface $checkIn,
        ?CarbonInterface $checkOut,
        array $lines,
        ?string $promotionCode = null,
        int $adults = 1,
        int $children = 0,
    ): ManualBookingPreview {
        $currency = CountrySettings::for($listing)->currency();

        if ($checkIn === null || $checkOut === null) {
            return new ManualBookingPreview(0.0, $currency, 0, ['Choose an arrival and a departure date.']);
        }

        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();
        $nights = (int) $in->diffInDays($out);

        if ($out->lte($in)) {
            return new ManualBookingPreview(0.0, $currency, 0, ['The departure date has to be after the arrival date.']);
        }

        $rooms = $this->resolveRooms($listing, $lines);

        if ($rooms === []) {
            return new ManualBookingPreview(0.0, $currency, $nights, ['Choose at least one room type.']);
        }

        // Two lines of the same room type are two rooms, so the availability
        // question is about their sum. Asking per line would tell a desk that
        // both of them fit when only one room is left.
        $demand = [];

        foreach ($rooms as $room) {
            $key = $this->demandKey($room);
            $demand[$key] = ($demand[$key] ?? 0) + $room->quantity;
        }

        $problems = [];
        $previews = [];
        $total = 0.0;
        $checked = [];

        foreach ($rooms as $room) {
            $roomType = $room->roomType;
            $quantity = $room->quantity;
            $plan = $room->ratePlan;
            $slot = $room->slot;

            // A departure has its own counter, so it is its own question. The
            // property's units are not what is short when the 09:00 tour is
            // full, and saying so would send a desk looking for a room.
            $free = $slot === null
                ? $this->calendar->unitsFreeThroughout($roomType, $in, $out)
                : $this->calendar->seatsFreeThroughout($roomType, $slot, $in, $out);

            if (! isset($checked[$this->demandKey($room)])) {
                $checked[$this->demandKey($room)] = true;
                $wanted = $demand[$this->demandKey($room)];

                if ($free < $wanted) {
                    $problems[] = $this->shortfall($roomType, $slot, $in, $out, $wanted);
                }

                // Stay restrictions are rules about selling a *night* — a
                // minimum stay, a day nobody may arrive on. A seat on a tour
                // has none of those, and checking the night's rules would let
                // a lodge's three-night minimum refuse a morning ride.
                if ($slot === null) {
                    try {
                        $this->calendar->assertStayRules($roomType, $in, $out, $plan);
                    } catch (StayRuleViolationException $violation) {
                        $problems[] = $roomType->name.': '.$violation->getMessage();
                    }
                }
            }

            try {
                $quote = $this->calendar->quote($roomType, $in, $out, $quantity, $plan, $room->occupancy, $slot);
            } catch (UnpriceableStayException $unpriceable) {
                // A room that cannot be priced is not a room that can be
                // booked, so this is a problem rather than a zero.
                $problems[] = $unpriceable->getMessage();

                continue;
            }

            $total += $quote->total();

            $previews[] = new ManualBookingLinePreview(
                roomType: $roomType,
                quantity: $quantity,
                total: $quote->total(),
                currency: $quote->currency,
                unitsFree: $free,
                occupancy: $room->occupancy,
                slot: $slot,
                dates: $nights,
            );

            $currency = $quote->currency;
        }

        $total = round($total, 2);
        $discount = 0.0;
        $offer = null;

        // The same finder the writer uses, on the same finished number. A code
        // that does not work is a problem the desk is told about while the
        // guest is still there, not a full-price booking they find out about
        // later.
        try {
            $match = app(PromotionFinder::class)->find(
                $listing,
                new StaySummary(
                    checkIn: $in,
                    checkOut: $out,
                    total: $total,
                    nightlyAmounts: $this->nightlyAmounts($rooms, $in, $out),
                    ratePlanIds: $this->planIds($rooms, $listing),
                    roomTypeIds: array_map(fn (ResolvedRoomLine $room) => $room->roomType->id, $rooms),
                ),
                $promotionCode,
            );

            if ($match !== null) {
                $discount = $match->amount;
                $offer = $match->promotion->label();
                $total = round($total - $discount, 2);
            }
        } catch (PromotionUnavailableException $refusal) {
            $problems[] = $refusal->getMessage();
        }

        // The same calculator the writer uses, on the same finished number, so
        // the figure read out to a guest over the counter is the figure that
        // ends up on the reservation.
        $charges = app(ChargeCalculator::class)->for($listing, $in, new ChargeableStay(
            stayAmount: $total,
            nights: $nights,
            adults: $adults,
            children: $children,
            unitNights: $this->unitNights($rooms, $in, $out),
            currency: $currency,
        ));

        return new ManualBookingPreview(
            total: round($total + app(ChargeCalculator::class)->addedTotal($charges), 2),
            currency: $currency,
            nights: $nights,
            problems: $problems,
            lines: $previews,
            discount: $discount,
            offer: $offer,
            charges: $charges,
        );
    }

    /**
     * Rooms × nights, which is what a per-room levy counts.
     *
     * @param  array<int, ResolvedRoomLine>  $rooms
     */
    private function unitNights(array $rooms, CarbonInterface $in, CarbonInterface $out): int
    {
        $nights = (int) $in->diffInDays($out);
        $total = 0;

        foreach ($rooms as $room) {
            $total += $room->quantity * $nights;
        }

        return $total;
    }

    /**
     * Every night of the proposed stay as priced, which is what a free-nights
     * offer needs and nothing else looks at.
     *
     * @param  array<int, ResolvedRoomLine>  $rooms
     * @return array<int, float>
     */
    private function nightlyAmounts(array $rooms, Carbon $in, Carbon $out): array
    {
        $amounts = [];

        foreach ($rooms as $room) {
            try {
                $quote = $this->calendar->quote($room->roomType, $in, $out, $room->quantity, $room->ratePlan, $room->occupancy);
            } catch (UnpriceableStayException) {
                continue;
            }

            foreach ($quote->nights as $night) {
                $amounts[] = round($night->subtotal(), 2);
            }
        }

        return $amounts;
    }

    /**
     * @param  array<int, ResolvedRoomLine>  $rooms
     * @return array<int, int>
     */
    private function planIds(array $rooms, Listing $listing): array
    {
        $fallback = RatePlan::defaultFor($listing);
        $ids = [];

        foreach ($rooms as $room) {
            $plan = $room->ratePlan ?? $fallback;

            if ($plan !== null) {
                $ids[$plan->id] = $plan->id;
            }
        }

        return array_values($ids);
    }

    /**
     * Write the stay. Everything about inventory is the writer's decision, not
     * this class's — including refusing a booking this preview thought was
     * fine a moment ago.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function place(
        Listing $listing,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        array $lines,
        string $guestName,
        ReservationSource $source,
        ?string $guestEmail = null,
        ?string $guestPhone = null,
        int $adults = 1,
        int $children = 0,
        ?string $notes = null,
        ?int $createdBy = null,
        ?float $totalOverride = null,
        ?string $overrideReason = null,
        ?string $promotionCode = null,
    ): Reservation {
        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();

        $bookingLines = [];

        // A line names its plan only when the desk chose one. Left null, the
        // writer resolves the property's default — which is the one place that
        // decides, and where it belongs.
        foreach ($this->resolveRooms($listing, $lines) as $room) {
            $bookingLines[] = new BookingLine(
                $room->roomType,
                $room->quantity,
                $in,
                $out,
                $room->ratePlan,
                $room->occupancy,
                $room->slot,
            );
        }

        return $this->writer->book(new BookingRequest(
            listing: $listing,
            lines: $bookingLines,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestPhone: $guestPhone,
            source: $source,
            // A booking somebody typed in is one the property has already
            // agreed to. Provisional is for holds nobody has promised yet,
            // and a guest at the desk is not one of those.
            status: StayStatus::Confirmed,
            adults: $adults,
            children: $children,
            notes: $notes,
            createdBy: $createdBy,
            totalOverride: $totalOverride,
            overrideReason: $overrideReason,
            promotionCode: $promotionCode,
        ));
    }

    /**
     * Which night is actually short, and by how much. "Sold out" is not an
     * answer somebody can act on; "only one of two Standard Chalets is free on
     * 14 September" is — they can offer a different room or a different night.
     */
    private function shortfall(RoomType $room, ?BookingSlot $slot, Carbon $in, Carbon $out, int $quantity): string
    {
        $free = $slot === null
            ? $this->calendar->unitsFreeAcross($room, $in, $out)
            : $this->calendar->seatsFreeAcross($room, $slot, $in, $out);
        $worstDate = null;
        $worstFree = null;

        foreach ($free as $date => $units) {
            if ($worstFree === null || $units < $worstFree) {
                $worstFree = $units;
                $worstDate = $date;
            }
        }

        $when = $worstDate === null ? '' : ' on '.Carbon::parse($worstDate)->isoFormat('D MMMM');
        $units = max(0, (int) $worstFree);
        $what = $slot === null ? 'units' : 'seats';
        $name = $slot === null ? $room->name : $room->name.', '.$slot->label();

        return $units === 0
            ? "{$name} is fully booked{$when}."
            : "{$name}: only {$units} of {$quantity} requested {$what} are free{$when}.";
    }

    /**
     * Form rows into room types, dropping empty ones and refusing any that
     * does not belong to this property.
     *
     * Rows that say nothing about who is in the room are merged, because a
     * desk adding "2 standard" twice means four and two identical lines would
     * read as two bookings on the calendar. Rows *with* occupancy are never
     * merged: two adults in one chalet and a family in the next are different
     * prices, and merging them would lose the difference. That is the same
     * reason a room line with occupancy holds one room — see the
     * reservation_guests migration.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, ResolvedRoomLine>
     */
    private function resolveRooms(Listing $listing, array $lines): array
    {
        $roomTypeIds = [];

        foreach ($lines as $line) {
            $roomTypeIds[] = (int) ($line['room_type_id'] ?? 0);
        }

        $roomTypeIds = array_values(array_filter($roomTypeIds, fn (int $id) => $id > 0));

        if ($roomTypeIds === []) {
            return [];
        }

        $rooms = $listing->roomTypes()->whereIn('id', $roomTypeIds)->get()->keyBy('id');
        $plans = RatePlan::forListing($listing)->keyBy('id');
        $slots = $this->slotsFor($rooms->keys()->all());

        $resolved = [];
        $merged = [];

        foreach ($lines as $line) {
            $room = $rooms->get((int) ($line['room_type_id'] ?? 0));

            if (! $room instanceof RoomType) {
                continue;
            }

            $occupancy = $this->occupancyFrom($line['guests'] ?? null);
            $quantity = $occupancy === null ? (int) ($line['quantity'] ?? 0) : 1;

            if ($quantity <= 0) {
                continue;
            }

            $planId = (int) ($line['rate_plan_id'] ?? 0);
            $plan = $planId > 0 ? $plans->get($planId) : null;
            $plan = $plan instanceof RatePlan ? $plan : null;

            // A departure id is resolved against the room it was named with,
            // never against the property: the 09:00 quad tour is not a
            // departure of the game drive, and a form can post anything.
            $slotId = (int) ($line['slot_id'] ?? 0);
            $slot = $slotId > 0 ? ($slots[$room->id][$slotId] ?? null) : null;

            if ($occupancy === null) {
                // Two rows of the same room on the same departure are one
                // line; the same room on two departures is two, because they
                // are two pools of seats.
                $key = $room->id.':'.($plan->id ?? 0).':'.($slot->id ?? 0);

                if (isset($merged[$key])) {
                    $resolved[$merged[$key]] = $resolved[$merged[$key]]->plus($quantity);

                    continue;
                }

                $merged[$key] = count($resolved);
            }

            $resolved[] = new ResolvedRoomLine($room, $quantity, $plan, $occupancy, $slot);
        }

        return $resolved;
    }

    /**
     * How much of one pool a set of lines is asking for. A room and a
     * departure of that room are different pools, so they cannot share a key —
     * added together they would report a full tour as a full property.
     */
    private function demandKey(ResolvedRoomLine $room): string
    {
        return $room->roomType->id.':'.($room->slot->id ?? 0);
    }

    /**
     * The active timetable of these units, keyed by unit and then by slot, in
     * one query.
     *
     * @param  array<int, int>  $roomTypeIds
     * @return array<int, array<int, BookingSlot>>
     */
    private function slotsFor(array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $slots = [];

        foreach (BookingSlot::query()->whereIn('room_type_id', $roomTypeIds)->where('is_active', true)->get() as $slot) {
            $slots[$slot->room_type_id][$slot->id] = $slot;
        }

        return $slots;
    }

    /**
     * A row's guest counts, or null when the row does not say — which is the
     * normal state for a property that prices per room and never asks.
     */
    private function occupancyFrom(mixed $guests): ?Occupancy
    {
        if (! is_array($guests)) {
            return null;
        }

        $counts = [];

        foreach ($guests as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = (int) ($row['guest_category_id'] ?? 0);
            $count = (int) ($row['count'] ?? 0);

            if ($categoryId > 0 && $count > 0) {
                $counts[$categoryId] = ($counts[$categoryId] ?? 0) + $count;
            }
        }

        return $counts === [] ? null : Occupancy::of($counts);
    }
}
