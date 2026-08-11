<?php

namespace App\Enums;

/**
 * Whether an amenity is asked about of a room, of the property, or of both.
 *
 * One vocabulary with a scope rather than two catalogues: a pool belongs to
 * the lodge and a mosquito net to the chalet, but Wi-Fi is asked about at both
 * levels — and as two separate entries the two would drift apart within a
 * year, which is the whole failure a shared catalogue exists to prevent.
 */
enum AmenityScope: string
{
    case Room = 'room';

    case Property = 'property';

    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Rooms',
            self::Property => 'The property',
            self::Both => 'Both',
        };
    }

    public function appliesToRooms(): bool
    {
        return $this !== self::Property;
    }

    public function appliesToProperties(): bool
    {
        return $this !== self::Room;
    }
}
