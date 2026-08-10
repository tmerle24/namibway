<?php

namespace App\Services\ImportExport;

/**
 * How a single workbook column is read from / written to a Listing. The type
 * drives three things at once: how the cell is rendered on export, how the
 * raw cell text is parsed on import, and how "did this row actually change?"
 * is decided (a price must compare numerically, a description as plain text).
 */
enum SheetColumnType
{
    /** The listing's primary key — the only safe update key (see ListingImporter). */
    case Id;

    case Slug;

    /** Plain, non-translatable string column. */
    case Text;

    /** spatie/laravel-translatable string, read/written in the fallback locale. */
    case Translatable;

    /**
     * Translatable column that is stored as (sanitized) HTML but edited as plain
     * text in the sheet — see ListingSheet::toPlainText() for why.
     */
    case RichText;

    case Decimal;

    case Integer;

    case Boolean;

    case ListingTypeEnum;

    case VehicleCategoryEnum;

    /** City name in the sheet, city_id in the database. */
    case City;

    /** A single "lat, lng" cell that fills latitude + longitude. */
    case Coordinates;

    /** Folder name inside the photo ZIP; fills image + gallery (see PhotoArchive). */
    case PhotoFolder;
}
