<?php

namespace App\Services\Booking;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\RoomType;
use App\Services\Inventory\AvailabilityCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How many units of a room type are still free for a date range.
 *
 * Two counts, and the answer is the smaller of them.
 *
 * **The calendar** — `room_type_calendar_days`, the ARI counters the lodge
 * sells from — knows every stay the property has taken, including the walk-in
 * somebody typed in at the desk this morning. Until 2026-08-12 the
 * traveller-facing picker did not read it at all, so a lodge could fill itself
 * up in its own panel while the trip plan carried on offering the same rooms.
 *
 * **Requests that hold no room** are the second count, and it is shrinking. A
 * request on a property whose inventory we hold now takes a provisional stay
 * on the calendar (see App\Services\Booking\StayPromoter::hold), so it is
 * counted there like everything else. What is left over is requests that could
 * not take one: a partner on somebody else's PMS, a request with no dates, a
 * room that went while the request was in the post. Those are still subtracted
 * here, because three travellers asking about the last room is a reason not to
 * offer it to a fourth.
 *
 * The two never double-count: a request that holds its room is excluded from
 * the second count precisely *because* it holds it. So the seam closes itself
 * as coverage grows — when every request takes a hold, the subtraction is
 * always zero and the calendar is the only counter, with no further change
 * here.
 *
 * `Inquiry::room_type_code` matches `RoomType::code` (a string, not a foreign
 * key — see the RoomType model), so a room type that is renamed keeps its
 * bookings and one that changes its code does not.
 */
class RoomAvailability
{
    /**
     * Units of this room type still bookable across the whole range. Can go
     * negative in theory (units reduced after requests were taken); callers
     * treat anything below 1 as sold out.
     */
    public static function unitsLeft(
        int $listingId,
        RoomType $roomType,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
    ): int {
        $overlapping = Inquiry::where('listing_id', $listingId)
            ->where('room_type_code', $roomType->code)
            ->whereIn('status', [InquiryStatus::OnRequest, InquiryStatus::Confirmed])
            // Half-open interval: a stay ending on the day another begins does
            // not overlap it, so the room turns over the same day.
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            // A request holding a room on the calendar is already counted
            // there. Counting it here as well would take the same room twice
            // and show a property fuller than it is.
            ->whereDoesntHave('reservation')
            ->count();

        $afterRequests = $roomType->total_units - $overlapping;

        // What the lodge's own calendar has left. Sparse by design: a room
        // type with no rows falls back to `total_units`, so a property that
        // has never opened the calendar reads exactly as it did before.
        $onCalendar = app(AvailabilityCalendar::class)->unitsFreeThroughout($roomType, $checkIn, $checkOut);

        return min($afterRequests, $onCalendar);
    }

    /**
     * Active room types of a listing that still have a free unit for the range
     * and can seat the party, each paired with its remaining unit count.
     *
     * Party size is a filter here and not in unitsLeft(): "is this room free"
     * and "does this room fit us" are different questions, and the connector
     * asks only the first.
     *
     * `units_left` is typed as at least 1 because sold-out rows are filtered
     * out — a caller never has to check it before offering the room.
     *
     * @return Collection<int, array{room_type: RoomType, units_left: int<1, max>}>
     */
    public static function bookableFor(
        int $listingId,
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $adults = 1,
        int $children = 0,
    ): Collection {
        return RoomType::query()
            ->where('listing_id', $listingId)
            ->where('is_active', true)
            ->orderBy('rate_per_night')
            ->get()
            ->map(fn (RoomType $roomType) => [
                'room_type' => $roomType,
                'units_left' => self::unitsLeft($listingId, $roomType, $checkIn, $checkOut),
            ])
            ->filter(fn (array $row) => $row['units_left'] >= 1 && RoomCapacity::fits($row['room_type'], $adults, $children))
            ->values();
    }

    // Whether a party fits a room now lives on RoomCapacity, because this
    // screen declining to *offer* a room was the only opinion anything had
    // about capacity — and a filter is not a rule. See BOOKING_SYSTEM.md.
}
