<?php

namespace App\Services\Booking;

use App\Models\RoomType;

/**
 * How many people a room sleeps, and whether a party fits in it.
 *
 * This existed as a private helper on RoomAvailability, where it decided which
 * rooms the trip plan **offers** — a filter and not a rule. Nothing anywhere
 * *accepted* it, which is how a room for 2 + 2 took a booking for 2 + 3 without
 * a word (BOOKING_SYSTEM.md, "capacity is a filter, not a rule"). Pulled out
 * here so the three places that now have an opinion share one arithmetic:
 *
 * - the picker, which declines to offer a room that does not fit;
 * - the traveller-facing request, which refuses one;
 * - the desk, which warns and takes an answer.
 *
 * Those are three different *answers* to one question, on purpose. At a desk
 * exceeding capacity is legitimate — a cot goes in the room — and a
 * receptionist told "no" by software they cannot argue with writes the booking
 * on paper instead. On the website nobody is there to judge whether the room
 * can take a fourth child.
 */
class RoomCapacity
{
    /**
     * A child may take a spare adult slot, because a room's limit is beds and
     * not categories — a family of two adults and one child fits a room for
     * three adults. The reverse is not true: an adult never occupies a child
     * slot, since a child slot is often a bunk or a fold-out.
     */
    public static function fits(RoomType $roomType, int $adults, int $children): bool
    {
        return self::fitsAcross([[$roomType, 1]], $adults, $children);
    }

    /**
     * Whether a party fits across a whole booking's rooms.
     *
     * Summed rather than attributed. A stay holding one double and one family
     * room under a per-room tariff has guest counts on the header and nothing
     * that says which guest is in which room; splitting them would be inventing
     * an attribution, and the honest question is whether the party fits in what
     * was booked at all. Seven people in two doubles is over capacity however
     * they arrange themselves.
     *
     * @param  array<int, array{0: RoomType, 1: int}>  $lines  room type and quantity
     */
    public static function fitsAcross(array $lines, int $adults, int $children): bool
    {
        [$adultSlots, $childSlots] = self::slots($lines);

        // A room type nobody has filled capacity in says nothing, and refusing
        // every booking of it would be worse than not checking.
        if ($adultSlots < 1 && $childSlots < 1) {
            return true;
        }

        if ($adults > $adultSlots) {
            return false;
        }

        $childOverflow = max(0, $children - $childSlots);

        return $adults + $childOverflow <= $adultSlots;
    }

    /**
     * The sentence a person is shown, in the shape somebody at a desk can act
     * on: what was booked, what is being put in it, and by how much.
     *
     * "Standard Chalet sleeps 2 + 2 and this booking is 2 + 3" beats "capacity
     * exceeded", which tells a receptionist nothing they did not already know
     * and nothing about what to do next.
     *
     * @param  array<int, array{0: RoomType, 1: int}>  $lines
     */
    public static function explain(array $lines, int $adults, int $children): string
    {
        [$adultSlots, $childSlots] = self::slots($lines);

        $names = [];

        foreach ($lines as [$roomType, $quantity]) {
            $names[] = $quantity > 1 ? $roomType->name.' ×'.$quantity : $roomType->name;
        }

        $what = implode(' + ', array_unique($names));

        return "{$what} sleeps {$adultSlots} + {$childSlots}, and this booking is {$adults} + {$children}.";
    }

    /**
     * Adult and child slots across the lines, quantity included.
     *
     * @param  array<int, array{0: RoomType, 1: int}>  $lines
     * @return array{int, int}
     */
    private static function slots(array $lines): array
    {
        $adults = 0;
        $children = 0;

        foreach ($lines as [$roomType, $quantity]) {
            $adults += $roomType->max_adults * max(1, $quantity);
            $children += $roomType->max_children * max(1, $quantity);
        }

        return [$adults, $children];
    }
}
