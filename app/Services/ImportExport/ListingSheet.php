<?php

namespace App\Services\ImportExport;

use App\Enums\VehicleCategory;
use App\Models\Listing;

/**
 * The column definition shared by ListingExporter and ListingImporter — change a
 * column here and both ends follow, so an exported file always re-imports.
 *
 * Headers are English — the content team works in English, and they double as the
 * database field names. The German headers the first version shipped with are
 * still accepted as aliases, so a workbook downloaded back then keeps importing.
 *
 * Two rules make round-tripping safe (see also CLAUDE.md's data-loss lesson):
 * an empty cell means "leave this field alone", never "set it to NULL", and the
 * only automatic update key is the `id` column an export writes. Emptying a
 * field on purpose needs one of the CLEAR_MARKERS.
 */
final class ListingSheet
{
    /** Typed into a cell to deliberately empty a field. A bare "-" is NOT one — too easy to type by accident. */
    public const CLEAR_MARKERS = ['—', 'CLEAR', 'EMPTY'];

    public const SHEET_NAME = 'Listings';

    public const HELP_SHEET_NAME = 'Instructions';

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
                header: 'id',
                type: SheetColumnType::Id,
                attribute: 'id',
                help: 'Filled in by the export. Present = this listing is updated, empty = a new listing is created. Never edit it.',
                aliases: ['listing_id'],
                width: 8,
            ),
            new SheetColumn(
                header: 'name',
                type: SheetColumnType::Translatable,
                attribute: 'name',
                help: 'Name of the business. Required on new rows.',
                aliases: ['title'],
                requiredForNew: true,
                width: 34,
            ),
            new SheetColumn(
                header: 'type',
                type: SheetColumnType::ListingTypeEnum,
                attribute: 'type',
                help: 'accommodation, activity, restaurant or vehicle. Required on new rows.',
                aliases: ['typ'],
                requiredForNew: true,
                width: 16,
            ),
            new SheetColumn(
                header: 'vehicle_category',
                type: SheetColumnType::VehicleCategoryEnum,
                attribute: 'vehicle_category',
                help: 'Only when type = vehicle: self_drive (rental) or guided_tour (tour with a driver-guide).',
                aliases: ['fahrzeug_kategorie'],
                width: 18,
            ),
            new SheetColumn(
                header: 'city',
                type: SheetColumnType::City,
                attribute: 'city_id',
                help: 'A place from the list on the "'.self::HELP_SHEET_NAME.'" sheet. For duplicate names: "Name (Region)". Without a city the listing never appears in a Kaia trip plan.',
                aliases: ['stadt', 'ort'],
                width: 24,
            ),
            new SheetColumn(
                header: 'address',
                type: SheetColumnType::Text,
                attribute: 'address',
                help: 'Street address or directions. This is what the automatic coordinate lookup works from.',
                aliases: ['adresse'],
                width: 40,
            ),
            new SheetColumn(
                header: 'coordinates',
                type: SheetColumnType::Coordinates,
                attribute: 'latitude',
                help: 'One cell for both: "-22.482100, 17.095400". A Google Maps link works too, as do degrees-minutes like "S22°28.9 E17°05.7". Leave it empty — the address lookup fills it in later.',
                aliases: ['koordinaten', 'gps', 'lat_lng'],
                width: 26,
            ),
            new SheetColumn(
                header: 'short_description',
                type: SheetColumnType::Translatable,
                attribute: 'short_description',
                help: 'One or two sentences, shown on cards and in lists.',
                aliases: ['beschreibung_kurz'],
                width: 45,
            ),
            new SheetColumn(
                header: 'description',
                type: SheetColumnType::RichText,
                attribute: 'description',
                help: 'The full text for the detail page. Plain text is fine — any formatting is lost once the cell is edited in Excel.',
                aliases: ['beschreibung'],
                width: 60,
            ),
            new SheetColumn(
                header: 'price_from',
                type: SheetColumnType::Decimal,
                attribute: 'price_from',
                help: 'A number without a currency symbol, e.g. 1250 or 1250.50.',
                aliases: ['preis_ab', 'preis', 'price'],
                width: 12,
            ),
            new SheetColumn(
                header: 'currency',
                type: SheetColumnType::Text,
                attribute: 'price_currency',
                help: 'Currency of the price: '.implode(', ', self::supportedCurrencies()).'. Empty = NAD.',
                aliases: ['price_currency', 'waehrung'],
                width: 12,
            ),
            new SheetColumn(
                header: 'duration_minutes',
                type: SheetColumnType::Integer,
                attribute: 'duration_minutes',
                help: 'Activities only: how long it takes, in minutes (a half-day tour is 240).',
                aliases: ['dauer_min', 'dauer', 'duration'],
                width: 16,
            ),
            new SheetColumn(
                header: 'website',
                type: SheetColumnType::Text,
                attribute: 'website',
                help: 'Full address including https://',
                aliases: ['url'],
                width: 34,
            ),
            new SheetColumn(
                header: 'email',
                type: SheetColumnType::Text,
                attribute: 'contact_email',
                help: 'The address bookings and enquiries go to.',
                aliases: ['contact_email'],
                width: 30,
            ),
            new SheetColumn(
                header: 'contact_person',
                type: SheetColumnType::Text,
                attribute: 'contact_person',
                help: 'Who to ask for at the business.',
                aliases: ['kontaktperson'],
                width: 24,
            ),
            new SheetColumn(
                header: 'phone',
                type: SheetColumnType::Text,
                attribute: 'phone',
                help: 'With country code, e.g. +264 61 123456.',
                aliases: ['telefon'],
                width: 22,
            ),
            new SheetColumn(
                header: 'ntb_number',
                type: SheetColumnType::Text,
                attribute: 'ntb_number',
                help: 'Namibia Tourism Board registration number, if known.',
                aliases: ['ntb_nummer'],
                width: 16,
            ),
            new SheetColumn(
                header: 'published',
                type: SheetColumnType::Boolean,
                attribute: 'is_published',
                help: 'yes = live on the website, no = internal only. New rows without an answer stay hidden.',
                aliases: ['is_published', 'veroeffentlichen'],
                width: 14,
            ),
            new SheetColumn(
                header: 'accepts_inquiries',
                type: SheetColumnType::Boolean,
                attribute: 'accepts_inquiries',
                help: 'yes = travellers can enquire and book. Empty = yes.',
                aliases: ['anfragen_moeglich'],
                width: 18,
            ),
            new SheetColumn(
                header: 'slug',
                type: SheetColumnType::Slug,
                attribute: 'slug',
                help: 'The listing\'s part of the URL. Generated from the name — only change it for a reason.',
                aliases: [],
                width: 30,
            ),
        ];
    }

    /**
     * Normalized header (incl. aliases) => column.
     *
     * @return array<string, SheetColumn>
     */
    public static function lookup(): array
    {
        if (self::$lookup !== null) {
            return self::$lookup;
        }

        $lookup = [];

        foreach (self::columns() as $column) {
            $lookup[self::normalize($column->header)] = $column;

            foreach ($column->aliases as $alias) {
                $lookup[self::normalize($alias)] = $column;
            }
        }

        return self::$lookup = $lookup;
    }

    public static function findColumn(string $header): ?SheetColumn
    {
        return self::lookup()[self::normalize($header)] ?? null;
    }

    /**
     * Headers are compared leniently: Excel adds a BOM, people add spaces, and
     * "Preis ab", "preis_ab" and "PREIS AB" all obviously mean the same column.
     */
    public static function normalize(string $header): string
    {
        $value = str_replace("\u{FEFF}", '', $header);
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    public static function isClearMarker(string $value): bool
    {
        return in_array(mb_strtoupper(trim($value)), array_map(mb_strtoupper(...), self::CLEAR_MARKERS), true);
    }

    /**
     * Translatable columns are read and written in one fixed locale rather than
     * the request locale — an import must not depend on who is logged in with
     * which UI language. The product ships English-first (see config/locales.php).
     */
    public static function locale(): string
    {
        $locale = config('app.fallback_locale');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * @return list<string>
     */
    public static function supportedCurrencies(): array
    {
        $supported = config('currencies.supported');

        return is_array($supported) ? array_values(array_filter($supported, is_string(...))) : ['NAD'];
    }

    /**
     * The value a column shows for a listing — used by the exporter for the sheet
     * itself and by the importer for the "old value" column of its diff report,
     * so both always speak the same dialect.
     */
    public static function cellValue(SheetColumn $column, Listing $listing): string|int|float|null
    {
        $locale = self::locale();

        return match ($column->type) {
            SheetColumnType::Id => $listing->id,
            SheetColumnType::Slug, SheetColumnType::Text => self::stringOrNull($listing->getAttribute($column->attribute)),
            SheetColumnType::Translatable => self::stringOrNull($listing->getTranslation($column->attribute, $locale, false)),
            SheetColumnType::RichText => self::toPlainText($listing->getTranslation($column->attribute, $locale, false)),
            SheetColumnType::Decimal => $listing->getAttribute($column->attribute) === null
                ? null
                : round((float) $listing->getAttribute($column->attribute), 2),
            SheetColumnType::Integer => $listing->getAttribute($column->attribute) === null
                ? null
                : (int) $listing->getAttribute($column->attribute),
            SheetColumnType::Boolean => $listing->getAttribute($column->attribute) === null
                ? null
                : ($listing->getAttribute($column->attribute) ? 'yes' : 'no'),
            SheetColumnType::ListingTypeEnum => $listing->type->value,
            SheetColumnType::VehicleCategoryEnum => $listing->vehicle_category instanceof VehicleCategory ? $listing->vehicle_category->value : null,
            SheetColumnType::City => $listing->city?->name,
            SheetColumnType::Coordinates => self::formatCoordinates($listing->latitude, $listing->longitude),
        };
    }

    public static function formatCoordinates(mixed $latitude, mixed $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return sprintf('%.6f, %.6f', (float) $latitude, (float) $longitude);
    }

    /**
     * `description` is stored as HTML (the admin panel has a rich-text editor) but
     * is edited as plain text in a spreadsheet cell. Exporting the raw markup would
     * mean a person who never touches the cell still re-imports HTML that then gets
     * escaped again on the way in — see Listing::sanitizeRichText(). Both ends
     * therefore compare and export the plain-text projection: an untouched cell is
     * a guaranteed no-op, and an edited one is written as the plain text it is.
     */
    public static function toPlainText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = trim($text);

        return $text === '' ? null : $text;
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
