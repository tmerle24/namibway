<?php

namespace App\Services\Booking;

use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\RoomType;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How many units of a room type are still free for a date range.
 *
 * Availability is derived, never stored: `total_units` minus the requests
 * already in flight that overlap the dates. There is no calendar table, so
 * this query *is* the source of truth — which is why it lives in one place
 * rather than being written out again wherever it's needed. It used to be a
 * private method on NativeConnector; the trip plan's room picker needs the
 * same answer, and a second copy would be a second thing to keep correct.
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
            ->count();

        return $roomType->total_units - $overlapping;
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
            ->filter(fn (array $row) => $row['units_left'] >= 1 && self::seats($row['room_type'], $adults, $children))
            ->values();
    }

    /**
     * Children are allowed to take an adult slot when the room has spare adult
     * capacity — a family of two adults and one child fits a room for three
     * adults. The reverse is not true: an adult never occupies a child slot.
     */
    private static function seats(RoomType $roomType, int $adults, int $children): bool
    {
        if ($adults > $roomType->max_adults) {
            return false;
        }

        $childOverflow = max(0, $children - $roomType->max_children);

        return $adults + $childOverflow <= $roomType->max_adults;
    }
}
