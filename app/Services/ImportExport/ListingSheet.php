<?php

namespace App\Services\ImportExport;

use App\Enums\VehicleCategory;
use App\Models\Listing;

/**
 * The column definition shared by ListingExporter and ListingImporter — change a
 * column here and both ends follow, so an exported file always re-imports.
 *
 * Headers are German because the workbook is a data-entry tool for the content
 * team, not a product surface; the database field name and the English label are
 * accepted as aliases so a renamed header never breaks an import.
 *
 * Two rules make round-tripping safe (see also CLAUDE.md's data-loss lesson):
 * an empty cell means "leave this field alone", never "set it to NULL", and the
 * only automatic update key is the `id` column an export writes. Emptying a
 * field on purpose needs one of the CLEAR_MARKERS.
 */
final class ListingSheet
{
    /** Typed into a cell to deliberately empty a field. A bare "-" is NOT one — too easy to type by accident. */
    public const CLEAR_MARKERS = ['—', 'LEEREN', 'CLEAR'];

    public const SHEET_NAME = 'Listings';

    public const HELP_SHEET_NAME = 'Anleitung';

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
                help: 'Vom Export gesetzt. Gefüllt = dieses Listing wird aktualisiert, leer = neues Listing. Niemals selbst ändern.',
                aliases: ['listing_id'],
                width: 8,
            ),
            new SheetColumn(
                header: 'name',
                type: SheetColumnType::Translatable,
                attribute: 'name',
                help: 'Name des Betriebs. Pflicht bei neuen Zeilen.',
                aliases: ['title'],
                requiredForNew: true,
                width: 34,
            ),
            new SheetColumn(
                header: 'typ',
                type: SheetColumnType::ListingTypeEnum,
                attribute: 'type',
                help: 'accommodation, activity, restaurant oder vehicle. Pflicht bei neuen Zeilen.',
                aliases: ['type'],
                requiredForNew: true,
                width: 16,
            ),
            new SheetColumn(
                header: 'fahrzeug_kategorie',
                type: SheetColumnType::VehicleCategoryEnum,
                attribute: 'vehicle_category',
                help: 'Nur bei typ = vehicle: self_drive (Mietwagen) oder guided_tour (geführte Tour).',
                aliases: ['vehicle_category'],
                width: 18,
            ),
            new SheetColumn(
                header: 'stadt',
                type: SheetColumnType::City,
                attribute: 'city_id',
                help: 'Ort aus der Städteliste (Blatt "Anleitung"). Bei doppelten Namen: "Name (Region)". Ohne Ort taucht das Listing in Kaias Reiseplan nicht auf.',
                aliases: ['city', 'ort'],
                width: 24,
            ),
            new SheetColumn(
                header: 'adresse',
                type: SheetColumnType::Text,
                attribute: 'address',
                help: 'Anschrift oder Wegbeschreibung. Grundlage für die automatische Koordinatensuche.',
                aliases: ['address'],
                width: 40,
            ),
            new SheetColumn(
                header: 'koordinaten',
                type: SheetColumnType::Coordinates,
                attribute: 'latitude',
                help: 'Ein Feld für beides: "-22.482100, 17.095400". Akzeptiert auch einen Google-Maps-Link und Grad-Minuten wie "S22°28.9 E17°05.7". Leer lassen — die Adresssuche füllt es später.',
                aliases: ['coordinates', 'gps', 'lat_lng'],
                width: 26,
            ),
            new SheetColumn(
                header: 'beschreibung_kurz',
                type: SheetColumnType::Translatable,
                attribute: 'short_description',
                help: 'Ein bis zwei Sätze für Karten und Listen.',
                aliases: ['short_description'],
                width: 45,
            ),
            new SheetColumn(
                header: 'beschreibung',
                type: SheetColumnType::RichText,
                attribute: 'description',
                help: 'Ausführlicher Text für die Detailseite. Reiner Text genügt — Formatierungen gehen beim Bearbeiten in Excel verloren.',
                aliases: ['description'],
                width: 60,
            ),
            new SheetColumn(
                header: 'preis_ab',
                type: SheetColumnType::Decimal,
                attribute: 'price_from',
                help: 'Zahl ohne Währungszeichen, z. B. 1250 oder 1250,50.',
                aliases: ['price_from', 'preis'],
                width: 12,
            ),
            new SheetColumn(
                header: 'waehrung',
                type: SheetColumnType::Text,
                attribute: 'price_currency',
                help: 'Währung des Preises: '.implode(', ', self::supportedCurrencies()).'. Leer = NAD.',
                aliases: ['price_currency', 'currency'],
                width: 12,
            ),
            new SheetColumn(
                header: 'dauer_min',
                type: SheetColumnType::Integer,
                attribute: 'duration_minutes',
                help: 'Nur Aktivitäten: Dauer in Minuten (Halbtagestour = 240).',
                aliases: ['duration_minutes', 'dauer'],
                width: 12,
            ),
            new SheetColumn(
                header: 'website',
                type: SheetColumnType::Text,
                attribute: 'website',
                help: 'Vollständige Adresse inkl. https://',
                aliases: ['url'],
                width: 34,
            ),
            new SheetColumn(
                header: 'email',
                type: SheetColumnType::Text,
                attribute: 'contact_email',
                help: 'Buchungs-/Kontaktadresse des Betriebs.',
                aliases: ['contact_email'],
                width: 30,
            ),
            new SheetColumn(
                header: 'kontaktperson',
                type: SheetColumnType::Text,
                attribute: 'contact_person',
                help: 'Ansprechpartner beim Betrieb.',
                aliases: ['contact_person'],
                width: 24,
            ),
            new SheetColumn(
                header: 'telefon',
                type: SheetColumnType::Text,
                attribute: 'phone',
                help: 'Mit Ländervorwahl, z. B. +264 61 123456.',
                aliases: ['phone'],
                width: 22,
            ),
            new SheetColumn(
                header: 'ntb_nummer',
                type: SheetColumnType::Text,
                attribute: 'ntb_number',
                help: 'Registriernummer beim Namibia Tourism Board, falls bekannt.',
                aliases: ['ntb_number'],
                width: 16,
            ),
            new SheetColumn(
                header: 'veroeffentlichen',
                type: SheetColumnType::Boolean,
                attribute: 'is_published',
                help: 'ja = auf der Website sichtbar, nein = nur intern. Neue Zeilen ohne Angabe bleiben unsichtbar.',
                aliases: ['is_published', 'published'],
                width: 16,
            ),
            new SheetColumn(
                header: 'anfragen_moeglich',
                type: SheetColumnType::Boolean,
                attribute: 'accepts_inquiries',
                help: 'ja = Gäste können anfragen/buchen. Leer = ja.',
                aliases: ['accepts_inquiries'],
                width: 18,
            ),
            new SheetColumn(
                header: 'slug',
                type: SheetColumnType::Slug,
                attribute: 'slug',
                help: 'Teil der URL. Wird aus dem Namen erzeugt — nur ändern, wenn es einen Grund gibt.',
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
                : ($listing->getAttribute($column->attribute) ? 'ja' : 'nein'),
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
