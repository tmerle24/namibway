<?php

namespace App\Services\ImportExport;

use App\Models\BookableUnit;

/**
 * The "BookableUnits" sheet of the listings workbook: the bookable units of an
 * accommodation (see App\Models\BookableUnit), one row per room type.
 *
 * Unlike a listing, a room type has a natural key — `listing_id` + `code` is
 * unique in the database — so a row can be matched without an internal id, and
 * the same sheet both creates and updates. Rooms that are in the database but
 * not in the sheet are left alone: an import never deletes.
 */
final class BookableUnitSheet
{
    public const SHEET_NAME = 'RoomTypes';

    /** @var list<SheetColumn>|null */
    private static ?array $columns = null;

    /** @var array<string, SheetColumn>|null */
    private static ?array $lookup = null;

    /**
     * @return list<SheetColumn>
     */
    public static function columns(): array
    {
        return self::$columns ??= [
            new SheetColumn(
                header: 'listing_id',
                type: SheetColumnType::Id,
                attribute: 'listing_id',
                help: 'Which listing this room belongs to. Copy it from the id column on the Listings sheet.',
                aliases: ['id'],
                width: 10,
            ),
            new SheetColumn(
                header: 'listing',
                type: SheetColumnType::Text,
                attribute: '',
                help: 'The listing\'s name, only so the sheet is readable. Used to find the listing when listing_id is empty — which is how you add rooms to a listing the same import is creating.',
                aliases: ['listing_name'],
                width: 32,
            ),
            new SheetColumn(
                header: 'code',
                type: SheetColumnType::Text,
                attribute: 'code',
                help: 'Short identifier, unique per listing, e.g. "STD" or "FAM". This is what matches a row to an existing room type — changing it creates a new one.',
                aliases: ['room_code'],
                requiredForNew: true,
                width: 12,
            ),
            new SheetColumn(
                header: 'name',
                type: SheetColumnType::Text,
                attribute: 'name',
                help: 'What guests see, e.g. "Standard Chalet".',
                aliases: ['room_name'],
                requiredForNew: true,
                width: 28,
            ),
            new SheetColumn(
                header: 'description',
                type: SheetColumnType::Text,
                attribute: 'description',
                help: 'A sentence or two about the room.',
                aliases: [],
                width: 50,
            ),
            new SheetColumn(
                header: 'max_adults',
                type: SheetColumnType::Integer,
                attribute: 'max_adults',
                help: 'How many adults sleep in it. Empty = 2.',
                aliases: ['adults'],
                width: 12,
            ),
            new SheetColumn(
                header: 'max_children',
                type: SheetColumnType::Integer,
                attribute: 'max_children',
                help: 'How many children on top of that. Empty = 0.',
                aliases: ['children'],
                width: 14,
            ),
            new SheetColumn(
                header: 'total_units',
                type: SheetColumnType::Integer,
                attribute: 'total_units',
                help: 'How many rooms of this type exist. This is what availability counts down from.',
                aliases: ['units', 'rooms'],
                requiredForNew: true,
                width: 12,
            ),
            new SheetColumn(
                header: 'rate_per_night',
                type: SheetColumnType::Decimal,
                attribute: 'rate_per_night',
                help: 'Price per night for the room, without a currency symbol.',
                aliases: ['rate', 'price_per_night'],
                requiredForNew: true,
                width: 16,
            ),
            new SheetColumn(
                header: 'currency',
                type: SheetColumnType::Text,
                attribute: 'currency',
                help: 'Currency of the rate: '.implode(', ', ListingSheet::supportedCurrencies()).'. Empty = NAD.',
                aliases: [],
                width: 12,
            ),
            new SheetColumn(
                header: 'photo_folder',
                type: SheetColumnType::PhotoFolder,
                attribute: 'gallery',
                help: 'This room\'s folder in the photo ZIP. Room folders usually sit inside the listing\'s folder, so write the path when the name alone is not unique — "Okonjima Bush Camp/STD" rather than "STD". Filling this in REPLACES the room\'s photos.',
                aliases: ['photos'],
                width: 30,
            ),
            new SheetColumn(
                header: 'active',
                type: SheetColumnType::Boolean,
                attribute: 'is_active',
                help: 'yes = bookable. Set it to no instead of deleting a room that is out of service.',
                aliases: ['is_active'],
                width: 10,
            ),
        ];
    }

    public static function findColumn(string $header): ?SheetColumn
    {
        if (self::$lookup === null) {
            $lookup = [];

            foreach (self::columns() as $column) {
                $lookup[ListingSheet::normalize($column->header)] = $column;

                foreach ($column->aliases as $alias) {
                    $lookup[ListingSheet::normalize($alias)] = $column;
                }
            }

            self::$lookup = $lookup;
        }

        return self::$lookup[ListingSheet::normalize($header)] ?? null;
    }

    public static function cellValue(SheetColumn $column, BookableUnit $bookableUnit): string|int|float|null
    {
        return match ($column->type) {
            SheetColumnType::Id => $bookableUnit->listing_id,
            // Input only, exactly like the Listings sheet: the folder a room's photos
            // came from isn't stored, and writing anything here would make an
            // untouched export re-upload them.
            SheetColumnType::PhotoFolder => null,
            SheetColumnType::Integer => (int) $bookableUnit->getAttribute($column->attribute),
            SheetColumnType::Decimal => round((float) $bookableUnit->getAttribute($column->attribute), 2),
            SheetColumnType::Boolean => $bookableUnit->getAttribute($column->attribute) ? 'yes' : 'no',
            default => $column->header === 'listing'
                ? $bookableUnit->listing?->getTranslation('name', ListingSheet::locale(), false)
                : self::stringOrNull($bookableUnit->getAttribute($column->attribute)),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_scalar($value) ? (string) $value : '';

        return $value === '' ? null : $value;
    }
}
