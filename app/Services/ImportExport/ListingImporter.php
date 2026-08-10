<?php

namespace App\Services\ImportExport;

use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\City;
use App\Models\Listing;
use App\Services\Enrichment\CoordinateTextParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads a listings workbook and turns it into an ImportPlan first — never
 * straight into writes. plan() validates and diffs every row, apply() executes
 * that same plan in one transaction.
 *
 * The two rules that keep a bulk import from becoming a bulk data loss (CLAUDE.md
 * has the war story):
 *
 *  - The only automatic update key is `id`, which only an export can produce. A
 *    row without an id whose name resolves to an existing listing is an ERROR,
 *    not a silent update and not a duplicate — the person is told which id to
 *    put in the cell.
 *  - An empty cell means "don't touch this field". Emptying a field on purpose
 *    takes one of ListingSheet::CLEAR_MARKERS.
 */
class ListingImporter
{
    /** @var array<string, list<City>> normalized city name => cities with that name */
    private array $citiesByName = [];

    /** @var array<string, int> slug => listing id */
    private array $slugsToId = [];

    /** @var array<string, list<int>> normalized name => listing ids */
    private array $idsByName = [];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function plan(string $path): ImportPlan
    {
        $file = $this->reader->read($path);

        /** @var array<int, SheetColumn> $map cell index => column */
        $map = [];
        $ignored = [];
        $fileErrors = [];
        $seen = [];

        foreach ($file['headers'] as $index => $header) {
            if ($header === '') {
                continue;
            }

            $column = ListingSheet::findColumn($header);

            if ($column === null) {
                $ignored[] = $header;

                continue;
            }

            if (isset($seen[$column->header])) {
                $fileErrors[] = "Die Spalte \"{$column->header}\" kommt mehrfach vor.";

                continue;
            }

            $seen[$column->header] = true;
            $map[$index] = $column;
        }

        if ($map === []) {
            $fileErrors[] = 'Keine bekannte Spalte gefunden. Die erste Zeile muss die Spaltenüberschriften enthalten — am einfachsten mit der Vorlage oder einem Export anfangen.';
        } elseif (! isset($seen['id']) && ! isset($seen['name'])) {
            $fileErrors[] = 'Die Datei braucht mindestens die Spalte "id" (aktualisieren) oder "name" (neu anlegen).';
        }

        if ($file['rows'] === [] && $fileErrors === []) {
            $fileErrors[] = 'Die Datei enthält keine Datenzeilen.';
        }

        if ($fileErrors !== []) {
            return new ImportPlan([], $fileErrors, $ignored);
        }

        $this->loadReferenceData();

        $rows = [];
        $idsInFile = [];
        $slugsInFile = [];

        foreach ($file['rows'] as $raw) {
            $values = [];

            foreach ($map as $index => $column) {
                $values[$column->header] = $raw['cells'][$index] ?? '';
            }

            $rows[] = $this->planRow($raw['line'], $values, $idsInFile, $slugsInFile);
        }

        return new ImportPlan($rows, [], $ignored);
    }

    /**
     * @return int number of listings written
     */
    public function apply(ImportPlan $plan): int
    {
        $rows = $plan->applicableRows();

        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($rows): int {
            $written = 0;
            $locale = ListingSheet::locale();

            foreach ($rows as $row) {
                $listing = $row->listingId !== null ? Listing::find($row->listingId) : new Listing;

                if ($listing === null) {
                    // Deleted between the preview and the import — skipping is safer
                    // than resurrecting it under a new id.
                    continue;
                }

                if ($row->isNew) {
                    $listing->data_source = 'manual';

                    // Nothing goes live by accident: a new listing is only published
                    // when the sheet says so explicitly.
                    $listing->is_published = false;
                }

                foreach ($row->attributes as $attribute => $value) {
                    $listing->setAttribute($attribute, $value);
                }

                foreach ($row->translations as $attribute => $value) {
                    if ($value === null) {
                        $listing->forgetTranslation($attribute, $locale);

                        continue;
                    }

                    $listing->setTranslation($attribute, $locale, $value);
                }

                $listing->save();
                $written++;
            }

            return $written;
        });
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<int, int>  $idsInFile
     * @param  array<string, int>  $slugsInFile
     */
    private function planRow(int $line, array $values, array &$idsInFile, array &$slugsInFile): PlannedRow
    {
        $row = new PlannedRow($line);
        $row->name = $values['name'] ?? '';

        $listing = $this->resolveListing($row, $values, $idsInFile, $slugsInFile);

        if (! $row->isValid()) {
            return $row;
        }

        if ($listing !== null && $row->name === '') {
            $row->name = (string) $listing->getTranslation('name', ListingSheet::locale(), false);
        }

        foreach (ListingSheet::columns() as $column) {
            if ($column->type === SheetColumnType::Id || ! array_key_exists($column->header, $values)) {
                continue;
            }

            $raw = $values[$column->header];

            if ($raw === '') {
                if ($row->isNew && $column->requiredForNew) {
                    $row->errors[] = "Spalte \"{$column->header}\" ist bei neuen Listings Pflicht.";
                }

                continue;
            }

            $this->applyCell($row, $listing, $column, $raw);
        }

        if ($row->isNew && ! isset($row->attributes['city_id'])) {
            $row->warnings[] = 'Ohne Ort ("stadt") erscheint das Listing nicht in Kaias Reiseplänen.';
        }

        if ($row->isNew && ! isset($row->attributes['latitude'])) {
            $row->warnings[] = 'Ohne Koordinaten: werden später aus der Adresse gesucht (namibway:backfill-listing-coordinates).';
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<int, int>  $idsInFile
     * @param  array<string, int>  $slugsInFile
     */
    private function resolveListing(PlannedRow $row, array $values, array &$idsInFile, array &$slugsInFile): ?Listing
    {
        $id = trim($values['id'] ?? '');

        if ($id !== '') {
            if (! ctype_digit($id)) {
                $row->errors[] = "Die id \"{$id}\" ist keine Zahl. Die Spalte id nie von Hand ausfüllen.";

                return null;
            }

            $id = (int) $id;

            if (isset($idsInFile[$id])) {
                $row->errors[] = "Die id {$id} kommt schon in Zeile {$idsInFile[$id]} vor.";

                return null;
            }

            $listing = Listing::find($id);

            if ($listing === null) {
                $row->errors[] = "Es gibt kein Listing mit der id {$id}.";

                return null;
            }

            $idsInFile[$id] = $row->line;
            $row->listingId = $id;

            return $listing;
        }

        $name = trim($values['name'] ?? '');
        $slug = trim($values['slug'] ?? '');

        if ($name === '' && $slug === '') {
            $row->errors[] = 'Zeile ohne id braucht einen Namen.';

            return null;
        }

        $slug = $slug !== '' ? Str::slug($slug) : Str::slug($name);

        if (isset($slugsInFile[$slug])) {
            $row->errors[] = "Der Name ergibt dieselbe Adresse (slug \"{$slug}\") wie Zeile {$slugsInFile[$slug]}.";

            return null;
        }

        // A row without an id must never turn into a silent update of an existing
        // listing, and never into a second copy of it either — so both the URL slug
        // and the plain name are checked, and a hit is reported with the id to type in.
        $existingIds = array_unique(array_merge(
            isset($this->slugsToId[$slug]) ? [$this->slugsToId[$slug]] : [],
            $this->idsByName[ListingSheet::normalize($name)] ?? [],
        ));

        if ($existingIds !== []) {
            $ids = implode(' oder ', $existingIds);
            $row->errors[] = count($existingIds) > 1
                ? "\"{$name}\" gibt es mehrfach (id {$ids}). Bitte die richtige id in die Spalte id eintragen."
                : "\"{$name}\" gibt es schon (id {$ids}). Zum Aktualisieren die {$ids} in die Spalte id eintragen — oder den Namen ändern, wenn es ein anderer Betrieb ist.";

            return null;
        }

        $slugsInFile[$slug] = $row->line;
        $row->isNew = true;

        if ($similar = $this->findSimilarListing($slug)) {
            $row->warnings[] = "Ähnlicher Name existiert bereits: \"{$similar['slug']}\" (id {$similar['id']}). Wenn es derselbe Betrieb ist, stattdessen die id eintragen.";
        }

        return null;
    }

    private function applyCell(PlannedRow $row, ?Listing $listing, SheetColumn $column, string $raw): void
    {
        $clear = ListingSheet::isClearMarker($raw);

        if ($clear && $column->requiredForNew) {
            $row->errors[] = "Spalte \"{$column->header}\" kann nicht geleert werden.";

            return;
        }

        /** @var array<string, mixed>|null $parsed attribute => value, or null on a parse error */
        $parsed = $clear
            ? $this->clearedAttributes($column)
            : $this->parseCell($row, $column, $raw);

        if ($parsed === null) {
            return;
        }

        $display = $clear ? null : $this->displayValue($column, $parsed);
        $old = $listing !== null ? $this->asString(ListingSheet::cellValue($column, $listing)) : null;

        if ($listing !== null && ! $this->differs($column, $listing, $parsed)) {
            return;
        }

        $row->changes[] = new FieldChange($column->header, $old, $display);

        foreach ($parsed as $attribute => $value) {
            if (in_array($column->type, [SheetColumnType::Translatable, SheetColumnType::RichText], true)) {
                $row->translations[$attribute] = $value === null ? null : (string) $value;

                continue;
            }

            $row->attributes[$attribute] = $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clearedAttributes(SheetColumn $column): array
    {
        if ($column->type === SheetColumnType::Coordinates) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [$column->attribute => null];
    }

    /**
     * @return array<string, mixed>|null attribute => value; null means the cell could not be read
     */
    private function parseCell(PlannedRow $row, SheetColumn $column, string $raw): ?array
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
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" ist keine Zahl.";

                    return null;
                }

                return [$column->attribute => round($number, 2)];

            case SheetColumnType::Integer:
                $number = $this->parseNumber($raw);

                if ($number === null) {
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" ist keine ganze Zahl.";

                    return null;
                }

                return [$column->attribute => (int) round($number)];

            case SheetColumnType::Boolean:
                $bool = $this->parseBoolean($raw);

                if ($bool === null) {
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" verstehe ich nicht — bitte ja oder nein.";

                    return null;
                }

                return [$column->attribute => $bool];

            case SheetColumnType::ListingTypeEnum:
                $type = ListingType::tryFrom(ListingSheet::normalize($raw));

                if ($type === null) {
                    $allowed = implode(', ', array_column(ListingType::cases(), 'value'));
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" ist kein gültiger Typ ({$allowed}).";

                    return null;
                }

                return [$column->attribute => $type];

            case SheetColumnType::VehicleCategoryEnum:
                $category = VehicleCategory::tryFrom(ListingSheet::normalize($raw));

                if ($category === null) {
                    $allowed = implode(', ', array_column(VehicleCategory::cases(), 'value'));
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" ist keine gültige Kategorie ({$allowed}).";

                    return null;
                }

                return [$column->attribute => $category];

            case SheetColumnType::City:
                $city = $this->resolveCity($raw);

                if ($city === null) {
                    $row->errors[] = "Spalte \"{$column->header}\": den Ort \"{$raw}\" gibt es nicht. Gültige Orte stehen im Blatt \"".ListingSheet::HELP_SHEET_NAME.'".';

                    return null;
                }

                if (is_string($city)) {
                    $row->errors[] = "Spalte \"{$column->header}\": \"{$raw}\" ist mehrdeutig — bitte {$city} schreiben.";

                    return null;
                }

                return [$column->attribute => $city->id];

            case SheetColumnType::Coordinates:
                $coordinates = $this->parseCoordinates($raw);

                if ($coordinates === null) {
                    $row->errors[] = "Spalte \"{$column->header}\": aus \"{$raw}\" kann ich keine Koordinaten lesen. Erwartet: \"-22.482100, 17.095400\" oder ein Google-Maps-Link.";

                    return null;
                }

                return ['latitude' => $coordinates[0], 'longitude' => $coordinates[1]];

            case SheetColumnType::Id:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function differs(SheetColumn $column, Listing $listing, array $parsed): bool
    {
        $locale = ListingSheet::locale();

        return match ($column->type) {
            SheetColumnType::Coordinates => ListingSheet::formatCoordinates($listing->latitude, $listing->longitude)
                !== ListingSheet::formatCoordinates($parsed['latitude'] ?? null, $parsed['longitude'] ?? null),
            SheetColumnType::Decimal => $this->numberOrNull($listing->getAttribute($column->attribute)) !== $this->numberOrNull($parsed[$column->attribute] ?? null),
            SheetColumnType::Integer => $this->intOrNull($listing->getAttribute($column->attribute)) !== $this->intOrNull($parsed[$column->attribute] ?? null),
            SheetColumnType::Boolean => (bool) $listing->getAttribute($column->attribute) !== (bool) ($parsed[$column->attribute] ?? false),
            SheetColumnType::City => $listing->city_id !== ($parsed['city_id'] ?? null),
            SheetColumnType::ListingTypeEnum, SheetColumnType::VehicleCategoryEnum => $listing->getAttribute($column->attribute) !== ($parsed[$column->attribute] ?? null),
            SheetColumnType::Translatable => (string) $listing->getTranslation($column->attribute, $locale, false) !== (string) ($parsed[$column->attribute] ?? ''),
            SheetColumnType::RichText => (string) ListingSheet::toPlainText($listing->getTranslation($column->attribute, $locale, false)) !== (string) ($parsed[$column->attribute] ?? ''),
            default => (string) $listing->getAttribute($column->attribute) !== (string) ($parsed[$column->attribute] ?? ''),
        };
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function displayValue(SheetColumn $column, array $parsed): ?string
    {
        if ($column->type === SheetColumnType::Coordinates) {
            return ListingSheet::formatCoordinates($parsed['latitude'] ?? null, $parsed['longitude'] ?? null);
        }

        $value = $parsed[$column->attribute] ?? null;

        return match (true) {
            $value === null => null,
            $column->type === SheetColumnType::Boolean => $value ? 'ja' : 'nein',
            $column->type === SheetColumnType::City => $this->cityName($value),
            $value instanceof ListingType, $value instanceof VehicleCategory => $value->value,
            default => $this->asString($value),
        };
    }

    private function cityName(mixed $cityId): ?string
    {
        return is_int($cityId) ? City::find($cityId)?->name : null;
    }

    private function asString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function numberOrNull(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
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

        $matches = $this->citiesByName[ListingSheet::normalize($value)] ?? [];

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
            return implode(' oder ', array_map(
                static fn (City $city) => "\"{$city->name} ({$city->region?->name})\"",
                $matches,
            ));
        }

        return $matches[0];
    }

    /**
     * @return array{id: int, slug: string}|null
     */
    private function findSimilarListing(string $slug): ?array
    {
        $prefix = substr($slug, 0, 4);

        if (strlen($prefix) < 4) {
            return null;
        }

        foreach ($this->slugsToId as $existing => $id) {
            if (! str_starts_with($existing, $prefix)) {
                continue;
            }

            if (levenshtein($slug, $existing) <= 3) {
                return ['id' => $id, 'slug' => $existing];
            }
        }

        return null;
    }

    private function loadReferenceData(): void
    {
        $this->citiesByName = [];
        $this->slugsToId = [];
        $this->idsByName = [];

        City::query()->with('region')->get()->each(function (City $city): void {
            $this->citiesByName[ListingSheet::normalize($city->name)][] = $city;
        });

        // Hydrated rather than plucked: `name` is a translatable JSON column, so the
        // readable value only exists on the model.
        Listing::query()->select(['id', 'name', 'slug'])->get()->each(function (Listing $listing): void {
            $this->slugsToId[(string) $listing->slug] = $listing->id;
            $this->idsByName[ListingSheet::normalize((string) $listing->name)][] = $listing->id;
        });
    }
}
