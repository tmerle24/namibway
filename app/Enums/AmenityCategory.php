<?php

namespace App\Enums;

/**
 * How a room's amenities are grouped when they are read.
 *
 * The grouping is for people, not for the database: a guest scanning a room
 * description wants the bathroom together and the power situation together,
 * and a lodge ticking forty boxes wants the same. Nothing in the system
 * behaves differently per category.
 *
 * Power is its own thing here rather than a footnote under "technology",
 * because in Namibia it is a real question a guest asks before booking: solar,
 * generator hours, or nothing after dark are all normal, and a room that says
 * nothing about it is a room somebody arrives at with a dead camera battery.
 */
enum AmenityCategory: string
{
    case Bathroom = 'bathroom';

    case Beds = 'beds';

    case Comfort = 'comfort';

    case Outdoors = 'outdoors';

    case FoodAndDrink = 'food_and_drink';

    case Power = 'power';

    case Accessibility = 'accessibility';

    case Services = 'services';

    public function label(): string
    {
        return match ($this) {
            self::Bathroom => 'Bathroom',
            self::Beds => 'Beds',
            self::Comfort => 'Comfort',
            self::Outdoors => 'Outdoors',
            self::FoodAndDrink => 'Food and drink',
            self::Power => 'Power and connectivity',
            self::Accessibility => 'Accessibility',
            self::Services => 'Services',
        };
    }

    /** The order a room description reads in, not alphabetical. */
    public function sort(): int
    {
        return match ($this) {
            self::Beds => 10,
            self::Bathroom => 20,
            self::Comfort => 30,
            self::Outdoors => 40,
            self::FoodAndDrink => 50,
            self::Power => 60,
            self::Services => 70,
            self::Accessibility => 80,
        };
    }

    /** @return array<int, self> */
    public static function inReadingOrder(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b) => $a->sort() <=> $b->sort());

        return $cases;
    }
}
