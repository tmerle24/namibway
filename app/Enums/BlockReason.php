<?php

namespace App\Enums;

/**
 * Why units are unavailable without a guest booking them. Kept as a small
 * fixed set rather than free text because the whole point of a block is that
 * an occupancy report can tell "sold out" from "we took rooms off sale".
 */
enum BlockReason: string
{
    case Maintenance = 'maintenance';

    case OwnerUse = 'owner_use';

    /** Rooms held for a group whose booking isn't firm yet — the case that
     * looks like a reservation but has no guest to attach it to. */
    case GroupHold = 'group_hold';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Maintenance',
            self::OwnerUse => 'Owner use',
            self::GroupHold => 'Group hold',
            self::Other => 'Other',
        };
    }
}
