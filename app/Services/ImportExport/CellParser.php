<?php

namespace App\Services\ImportExport;

use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\City;
use App\Services\Enrichment\CoordinateTextParser;
use Illuminate\Support\Str;

/**
 * Turns spreadsheet text into the values a model wants — shared by every sheet
 * in the workbook, so a price is read the same way on the Listings sheet as on
 * the RoomTypes sheet, and a city name resolves identically everywhere.
 *
 * It is deliberately forgiving about what a spreadsheet produces (thousands
 * separators either way round, a pasted Google Maps URL, "yes"/"x"/"1") and
 * unforgiving about what it can't read: an unparsable cell is an error, never a
 * silently dropped or zeroed value.
 */
class CellParser
{
    /** @var array<string, list<City>>|null normalized city name => cities with that name */
    private ?array $citiesByName = null;

    /**
     * Turns one raw cell into the attribute(s) it sets. Parse problems are appended
     * to $errors in the workbook's own language ("Column \"price_from\": ..."), so a
     * caller only has to decide what to do with a row that collected any.
     *
     * @param  list<string>  $errors
     * @return array<string, mixed>|null attribute => value; null means the cell could not be read
     */
    public function parse(SheetColumn $column, string $raw, array &$errors): ?array
    {
        switch ($column->type) {
            case SheetColumnType::Text:
                return [$column->attribute => $raw];

            case SheetColumnType::Slug:
                return [$column->attribute => Str::slug($raw)];

            case SheetColumnType::Translatable:
            case SheetColumnType::RichText:
                return [$column->attribute => $raw];

            case SheetColumnType::Decimal:
                $number = $this->parseNumber($raw);

                if ($number === null) {
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not a number.";

                    return null;
                }

                return [$column->attribute => round($number, 2)];

            case SheetColumnType::Integer:
                $number = $this->parseNumber($raw);

                if ($number === null) {
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not a whole number.";

                    return null;
                }

                return [$column->attribute => (int) round($number)];

            case SheetColumnType::Boolean:
                $bool = $this->parseBoolean($raw);

                if ($bool === null) {
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not clear — please write yes or no.";

                    return null;
                }

                return [$column->attribute => $bool];

            case SheetColumnType::ListingTypeEnum:
                $type = ListingType::tryFrom(ListingSheet::normalize($raw));

                if ($type === null) {
                    $allowed = implode(', ', array_column(ListingType::cases(), 'value'));
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not a valid type ({$allowed}).";

                    return null;
                }

                return [$column->attribute => $type];

            case SheetColumnType::VehicleCategoryEnum:
                $category = VehicleCategory::tryFrom(ListingSheet::normalize($raw));

                if ($category === null) {
                    $allowed = implode(', ', array_column(VehicleCategory::cases(), 'value'));
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not a valid category ({$allowed}).";

                    return null;
                }

                return [$column->attribute => $category];

            case SheetColumnType::City:
                $city = $this->resolveCity($raw);

                if ($city === null) {
                    $errors[] = "Column \"{$column->header}\": there is no place called \"{$raw}\". The valid ones are on the \"".ListingSheet::HELP_SHEET_NAME.'" sheet.';

                    return null;
                }

                if (is_string($city)) {
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is ambiguous — please write {$city}.";

                    return null;
                }

                return [$column->attribute => $city->id];

            case SheetColumnType::Coordinates:
                $coordinates = $this->parseCoordinates($raw);

                if ($coordinates === null) {
                    $errors[] = "Column \"{$column->header}\": \"{$raw}\" is not readable as coordinates. Expected \"-22.482100, 17.095400\" or a Google Maps link.";

                    return null;
                }

                return ['latitude' => $coordinates[0], 'longitude' => $coordinates[1]];

                // Both are handled by the importer itself, not by a value parser: an id
                // resolves a row to a record, a photo folder is read out of the ZIP.
            case SheetColumnType::Id:
            case SheetColumnType::PhotoFolder:
                return null;
        }
    }

    /**
     * Accepts what a spreadsheet actually produces: "1250", "1.250", "1.250,50",
     * "1,250.50", "N$ 1250". The last separator wins as the decimal point, and a
     * dot followed by exactly three digits is read as a thousands separator.
     */
    private function parseNumber(string $raw): ?float
    {
        $value = preg_replace('/[^0-9,.\-]/', '', $raw) ?? '';

        if ($value === '' || $value === '-') {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace('.', '', $value)
                : str_replace(',', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function parseBoolean(string $raw): ?bool
    {
        return match (ListingSheet::normalize($raw)) {
            'ja', 'j', 'yes', 'y', 'true', 'wahr', 'x', '1' => true,
            'nein', 'n', 'no', 'false', 'falsch', '0' => false,
            default => null,
        };
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function parseCoordinates(string $raw): ?array
    {
        $candidates = [];

        // Pasting the Google Maps URL straight from the browser is the fastest way
        // to capture a coordinate, so read both shapes Maps puts in its URLs.
        if (preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $raw, $m) === 1) {
            $candidates = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', $raw, $m) === 1) {
            $candidates = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('/^(-?\d+\.\d+)\s*[,;\s]\s*(-?\d+\.\d+)$/', trim($raw), $m) === 1) {
            $candidates = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('/^(-?\d+(?:,\d+)?)\s*[;\s]\s*(-?\d+(?:,\d+)?)$/', trim($raw), $m) === 1) {
            // Comma as the decimal separator — only unambiguous with a non-comma separator.
            $candidates = [(float) str_replace(',', '.', $m[1]), (float) str_replace(',', '.', $m[2])];
        } elseif (($parsed = CoordinateTextParser::parse($raw)) !== null) {
            $candidates = $parsed;
        }

        if ($candidates === []) {
            return null;
        }

        [$latitude, $longitude] = $candidates;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * @return City|string|null the city, a hint string when the name is ambiguous, or null when unknown
     */
    private function resolveCity(string $raw): City|string|null
    {
        $value = trim($raw);
        $region = null;

        if (preg_match('/^(.*?)\s*\((.+)\)\s*$/u', $value, $m) === 1) {
            $value = trim($m[1]);
            $region = ListingSheet::normalize($m[2]);
        }

        $matches = $this->cities()[ListingSheet::normalize($value)] ?? [];

        if ($region !== null) {
            $matches = array_values(array_filter(
                $matches,
                static fn (City $city) => ListingSheet::normalize((string) $city->region?->name) === $region,
            ));
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            return implode(' or ', array_map(
                static fn (City $city) => "\"{$city->name} ({$city->region?->name})\"",
                $matches,
            ));
        }

        return $matches[0];
    }

    /**
     * @return array<string, mixed> the attributes a CLEAR marker empties
     */
    public function cleared(SheetColumn $column): array
    {
        if ($column->type === SheetColumnType::Coordinates) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [$column->attribute => null];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function display(SheetColumn $column, array $parsed): ?string
    {
        if ($column->type === SheetColumnType::Coordinates) {
            return ListingSheet::formatCoordinates($parsed['latitude'] ?? null, $parsed['longitude'] ?? null);
        }

        $value = $parsed[$column->attribute] ?? null;

        return match (true) {
            $value === null => null,
            $column->type === SheetColumnType::Boolean => $value ? 'yes' : 'no',
            $column->type === SheetColumnType::City => $this->cityName($value),
            $value instanceof ListingType, $value instanceof VehicleCategory => $value->value,
            default => $this->asString($value),
        };
    }

    public function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    public function numberOrNull(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    public function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function cityName(mixed $cityId): ?string
    {
        return is_int($cityId) ? City::find($cityId)?->name : null;
    }

    /**
     * @return array<string, list<City>>
     */
    private function cities(): array
    {
        if ($this->citiesByName !== null) {
            return $this->citiesByName;
        }

        $cities = [];

        City::query()->with('region')->get()->each(static function (City $city) use (&$cities): void {
            $cities[ListingSheet::normalize($city->name)][] = $city;
        });

        return $this->citiesByName = $cities;
    }
}
