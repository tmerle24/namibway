<?php

namespace App\Services\Inventory;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\ManualBookingLinePreview;
use App\Services\Inventory\DTOs\ManualBookingPreview;
use App\Services\Pricing\Occupancy;
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
    public function preview(Listing $listing, ?CarbonInterface $checkIn, ?CarbonInterface $checkOut, array $lines): ManualBookingPreview
    {
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
            $demand[$room['room']->id] = ($demand[$room['room']->id] ?? 0) + $room['quantity'];
        }

        $problems = [];
        $previews = [];
        $total = 0.0;
        $checked = [];

        foreach ($rooms as $room) {
            $roomType = $room['room'];
            $quantity = $room['quantity'];
            $plan = $room['ratePlan'];
            $free = $this->calendar->unitsFreeThroughout($roomType, $in, $out);

            if (! isset($checked[$roomType->id])) {
                $checked[$roomType->id] = true;
                $wanted = $demand[$roomType->id];

                if ($free < $wanted) {
                    $problems[] = $this->shortfall($roomType, $in, $out, $wanted);
                }

                try {
                    $this->calendar->assertStayRules($roomType, $in, $out, $plan);
                } catch (StayRuleViolationException $violation) {
                    $problems[] = $roomType->name.': '.$violation->getMessage();
                }
            }

            try {
                $quote = $this->calendar->quote($roomType, $in, $out, $quantity, $plan, $room['occupancy']);
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
                occupancy: $room['occupancy'],
            );

            $currency = $quote->currency;
        }

        return new ManualBookingPreview(
            total: round($total, 2),
            currency: $currency,
            nights: $nights,
            problems: $problems,
            lines: $previews,
        );
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
    ): Reservation {
        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();

        $bookingLines = [];

        // A line names its plan only when the desk chose one. Left null, the
        // writer resolves the property's default — which is the one place that
        // decides, and where it belongs.
        foreach ($this->resolveRooms($listing, $lines) as $room) {
            $bookingLines[] = new BookingLine(
                $room['room'],
                $room['quantity'],
                $in,
                $out,
                $room['ratePlan'],
                $room['occupancy'],
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
        ));
    }

    /**
     * Which night is actually short, and by how much. "Sold out" is not an
     * answer somebody can act on; "only one of two Standard Chalets is free on
     * 14 September" is — they can offer a different room or a different night.
     */
    private function shortfall(RoomType $room, Carbon $in, Carbon $out, int $quantity): string
    {
        $free = $this->calendar->unitsFreeAcross($room, $in, $out);
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

        return $units === 0
            ? "{$room->name} is fully booked{$when}."
            : "{$room->name}: only {$units} of {$quantity} requested units are free{$when}.";
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
     * @return array<int, array{room: RoomType, quantity: int, ratePlan: RatePlan|null, occupancy: Occupancy|null}>
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

            if ($occupancy === null) {
                $key = $room->id.':'.($plan->id ?? 0);

                if (isset($merged[$key])) {
                    $resolved[$merged[$key]]['quantity'] += $quantity;

                    continue;
                }

                $merged[$key] = count($resolved);
            }

            $resolved[] = [
                'room' => $room,
                'quantity' => $quantity,
                'ratePlan' => $plan,
                'occupancy' => $occupancy,
            ];
        }

        return $resolved;
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
