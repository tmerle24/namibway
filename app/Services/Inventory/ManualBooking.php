<?php

namespace App\Services\Inventory;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\DTOs\ManualBookingLinePreview;
use App\Services\Inventory\DTOs\ManualBookingPreview;
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
     * @param  array<int, array{room_type_id?: int|string|null, quantity?: int|string|null}>  $lines
     */
    public function preview(Listing $listing, ?CarbonInterface $checkIn, ?CarbonInterface $checkOut, array $lines): ManualBookingPreview
    {
        $currency = CountrySettings::for($listing)->currency();
        $ratePlan = RatePlan::defaultFor($listing);

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

        $problems = [];
        $previews = [];
        $total = 0.0;

        foreach ($rooms as [$room, $quantity]) {
            $free = $this->calendar->unitsFreeThroughout($room, $in, $out);

            if ($free < $quantity) {
                $problems[] = $this->shortfall($room, $in, $out, $quantity);
            }

            try {
                $this->calendar->assertStayRules($room, $in, $out, $ratePlan);
            } catch (StayRuleViolationException $violation) {
                $problems[] = $room->name.': '.$violation->getMessage();
            }

            $quote = $this->calendar->quote($room, $in, $out, $quantity, $ratePlan);
            $total += $quote->total();

            $previews[] = new ManualBookingLinePreview(
                roomType: $room,
                quantity: $quantity,
                total: $quote->total(),
                currency: $quote->currency,
                unitsFree: $free,
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
     * @param  array<int, array{room_type_id?: int|string|null, quantity?: int|string|null}>  $lines
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

        // The writer resolves the plan again for the line it records. Passing
        // it here as well would be the same lookup twice; leaving it null lets
        // the one place that writes decide, which is where it belongs.
        foreach ($this->resolveRooms($listing, $lines) as [$room, $quantity]) {
            $bookingLines[] = new BookingLine($room, $quantity, $in, $out);
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
     * Same-room-type rows are merged rather than passed through as two lines:
     * a desk adding "2 standard" twice means four, and two lines for one room
     * type would price correctly but read as two bookings on the calendar.
     *
     * @param  array<int, array{room_type_id?: int|string|null, quantity?: int|string|null}>  $lines
     * @return array<int, array{0: RoomType, 1: int}>
     */
    private function resolveRooms(Listing $listing, array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) ($line['room_type_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($id <= 0 || $quantity <= 0) {
                continue;
            }

            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }

        if ($quantities === []) {
            return [];
        }

        $rooms = $listing->roomTypes()->whereIn('id', array_keys($quantities))->orderBy('id')->get();
        $resolved = [];

        foreach ($rooms as $room) {
            $resolved[] = [$room, $quantities[$room->id]];
        }

        return $resolved;
    }
}
