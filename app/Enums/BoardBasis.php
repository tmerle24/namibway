<?php

namespace App\Enums;

/**
 * What a rate includes at the table.
 *
 * This is a property of a rate plan and not a separate price dimension,
 * because that is how a lodge actually sells it: not "a room, and then
 * dinner", but a DBB rate and a B&B rate side by side. Modelling it as two
 * rate plans removes a whole layer and matches every quote a guest ever sees.
 *
 * Half board is spelled "dinner, bed and breakfast" in Southern Africa and
 * abbreviated DBB. At Namibian lodges it is closer to the rule than the
 * exception, which is why it is not an afterthought here.
 */
enum BoardBasis: string
{
    case RoomOnly = 'room_only';

    case BedAndBreakfast = 'bed_and_breakfast';

    /** Dinner, bed and breakfast. */
    case HalfBoard = 'half_board';

    case FullBoard = 'full_board';

    /** Meals and the property's own activities. */
    case AllInclusive = 'all_inclusive';

    public function label(): string
    {
        return match ($this) {
            self::RoomOnly => 'Room only',
            self::BedAndBreakfast => 'Bed and breakfast',
            self::HalfBoard => 'Dinner, bed and breakfast',
            self::FullBoard => 'Full board',
            self::AllInclusive => 'Fully inclusive',
        };
    }

    /** What a lodge writes on a rate sheet. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::RoomOnly => 'RO',
            self::BedAndBreakfast => 'B&B',
            self::HalfBoard => 'DBB',
            self::FullBoard => 'FB',
            self::AllInclusive => 'FI',
        };
    }
}
