<?php

namespace App\Services\ImportExport;

use App\Enums\ContentSource;
use App\Models\BookableUnit;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a listings workbook — the Listings sheet, the BookableUnits sheet, and the
 * photo ZIP that goes with them — and turns it into an ImportPlan first, never
 * straight into writes. plan() validates and diffs every row, apply() executes
 * that same plan in one transaction.
 *
 * The two rules that keep a bulk import from becoming a bulk data loss (CLAUDE.md
 * has the war story):
 *
 *  - The only automatic update key is `id`, which only an export can produce. A
 *    row without an id whose name resolves to an existing listing is an ERROR,
 *    not a silent update and not a duplicate — the person is told which id to
 *    put in the cell. Room types are the one exception, and only because they
 *    have a real natural key: listing + code is unique in the database.
 *  - An empty cell means "don't touch this field". Emptying a field on purpose
 *    takes one of ListingSheet::CLEAR_MARKERS. Nothing is ever deleted: rooms
 *    missing from the sheet stay, and replaced photos stay in the bucket for
 *    `photos:audit-r2` to deal with.
 */
class ListingImporter
{
    /** @var array<string, int> slug => listing id */
    private array $slugsToId = [];

    /** @var array<string, list<int>> normalized name => listing ids */
    private array $idsByName = [];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly CellParser $parser,
    ) {}

    public function plan(string $path, ?string $zipPath = null): ImportPlan
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
                $fileErrors[] = "The column \"{$column->header}\" appears more than once.";

                continue;
            }

            $seen[$column->header] = true;
            $map[$index] = $column;
        }

        if ($map === []) {
            $fileErrors[] = 'No known column found. The first row has to hold the column headers — the easiest start is the template or an export.';
        } elseif (! isset($seen['id']) && ! isset($seen['name'])) {
            $fileErrors[] = 'The file needs at least an "id" column (to update) or a "name" column (to create).';
        }

        if ($fileErrors !== []) {
            return new ImportPlan([], $fileErrors, $ignored);
        }

        $archive = null;

        if ($zipPath !== null) {
            try {
                $archive = PhotoArchive::open($zipPath);
            } catch (RuntimeException $exception) {
                return new ImportPlan([], [$exception->getMessage()], $ignored);
            }
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

            $rows[] = $this->planRow($raw['line'], $values, $idsInFile, $slugsInFile, $archive);
        }

        $roomRows = $this->planRoomRows($path, $rows, $archive);

        $archive?->close();

        if ($rows === [] && $roomRows === []) {
            return new ImportPlan([], ['The file has no data rows.'], $ignored);
        }

        return new ImportPlan($rows, [], $ignored, $roomRows);
    }

    /**
     * @return int number of listings and room types written
     */
    public function apply(ImportPlan $plan, ?string $zipPath = null): int
    {
        $rows = $plan->applicableRows();
        $roomRows = $plan->applicableRoomRows();

        if ($rows === [] && $roomRows === []) {
            return 0;
        }

        $archive = $zipPath !== null && $plan->needsPhotos() ? PhotoArchive::open($zipPath) : null;

        try {
            return DB::transaction(function () use ($rows, $roomRows, $archive): int {
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
                    $row->savedListingId = $listing->id;
                    $written++;

                    if ($row->photoFolder !== null && $archive !== null) {
                        $this->storePhotos($listing, $archive, $row->photoFolder);
                    }
                }

                foreach ($roomRows as $room) {
                    $listingId = $room->listingId ?? $room->listingRow?->savedListingId;

                    if ($listingId === null) {
                        continue;
                    }

                    $bookableUnit = BookableUnit::firstOrNew(['listing_id' => $listingId, 'code' => $room->code]);

                    foreach ($room->attributes as $attribute => $value) {
                        $bookableUnit->setAttribute($attribute, $value);
                    }

                    if ($room->photoFolder !== null && $archive !== null) {
                        $bookableUnit->gallery = $this->uploadPhotos(
                            $archive,
                            $room->photoFolder,
                            'room-types/'.((string) ($bookableUnit->listing()->value('slug') ?? $listingId))."/{$bookableUnit->code}",
                        );
                    }

                    $bookableUnit->save();
                    $written++;
                }

                return $written;
            });
        } finally {
            $archive?->close();
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<int, int>  $idsInFile
     * @param  array<string, int>  $slugsInFile
     */
    private function planRow(int $line, array $values, array &$idsInFile, array &$slugsInFile, ?PhotoArchive $archive): PlannedRow
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
                    $row->errors[] = "Column \"{$column->header}\" is required on new listings.";
                }

                continue;
            }

            if ($column->type === SheetColumnType::PhotoFolder) {
                $this->planPhotos($row, $listing, $raw, $archive);

                continue;
            }

            $this->applyCell($row, $listing, $column, $raw);
        }

        if ($row->isNew && ! isset($row->attributes['city_id'])) {
            $row->warnings[] = 'Without a city this listing never appears in a Kaia trip plan.';
        }

        if ($row->isNew && ! isset($row->attributes['latitude'])) {
            $row->warnings[] = 'No coordinates: they will be looked up from the address later (namibway:backfill-listing-coordinates).';
        }

        return $row;
    }

    /**
     * A filled-in photo folder REPLACES what the listing has — photos come as a set,
     * and merging two sets would leave nobody able to say which cover image wins.
     * The old files stay in the bucket; `photos:audit-r2` is what collects those.
     */
    private function planPhotos(PlannedRow $row, ?Listing $listing, string $raw, ?PhotoArchive $archive): void
    {
        $had = $listing !== null && ($listing->image || $listing->gallery) ? 'has photos' : null;

        if (ListingSheet::isClearMarker($raw)) {
            if ($had === null) {
                return;
            }

            $row->attributes['image'] = null;
            $row->attributes['gallery'] = [];
            $row->changes[] = new FieldChange('photo_folder', $had, 'no photos');

            return;
        }

        $photos = $this->photosIn($row->errors, $archive, $raw);

        if ($photos === null) {
            return;
        }

        $row->photoFolder = $raw;
        $row->changes[] = new FieldChange(
            'photo_folder',
            $had,
            count($photos).' photo(s), main image: '.$photos[0]['name'],
        );
    }

    /** Same all-or-nothing swap as a listing's photos, into the room's gallery. */
    private function planRoomPhotos(PlannedRoomRow $row, ?BookableUnit $bookableUnit, string $raw, ?PhotoArchive $archive): void
    {
        $had = $bookableUnit !== null && $bookableUnit->gallery ? count($bookableUnit->gallery).' photo(s)' : null;

        if (ListingSheet::isClearMarker($raw)) {
            if ($had === null) {
                return;
            }

            $row->attributes['gallery'] = [];
            $row->changes[] = new FieldChange('photo_folder', $had, 'no photos');

            return;
        }

        $photos = $this->photosIn($row->errors, $archive, $raw);

        if ($photos === null) {
            return;
        }

        $row->photoFolder = $raw;
        $row->changes[] = new FieldChange('photo_folder', $had, count($photos).' photo(s), first: '.$photos[0]['name']);
    }

    /**
     * The images a photo_folder cell points at, or null with the reason written into
     * $errors. Shared by listings and room types — both name a folder in the same ZIP.
     *
     * @param  list<string>  $errors
     * @return list<array{index: int, name: string, size: int}>|null
     */
    private function photosIn(array &$errors, ?PhotoArchive $archive, string $raw): ?array
    {
        if ($archive === null) {
            $errors[] = 'Column "photo_folder" is filled in, but no photo ZIP was uploaded.';

            return null;
        }

        $resolved = $archive->resolve($raw);

        if ($resolved === null) {
            $found = $archive->folderNames();
            $errors[] = "Column \"photo_folder\": the ZIP has no folder called \"{$raw}\"."
                .($found === [] ? ' The ZIP contains no folders with images in them.' : ' It contains: '.implode(', ', array_slice($found, 0, 12)).(count($found) > 12 ? ', …' : ''));

            return null;
        }

        if (is_array($resolved)) {
            // Every lodge has an "STD" room, so a bare folder name is not always enough.
            $errors[] = "Column \"photo_folder\": \"{$raw}\" matches several folders in the ZIP ("
                .implode(', ', $resolved).'). Write more of the path, e.g. "'.$resolved[0].'".';

            return null;
        }

        $photos = $archive->photos($raw);

        if ($photos === []) {
            $errors[] = "Column \"photo_folder\": the folder \"{$raw}\" has no ".implode('/', PhotoArchive::EXTENSIONS).' images in it.';

            return null;
        }

        if (count($photos) > PhotoArchive::MAX_FILES_PER_FOLDER) {
            $errors[] = "Column \"photo_folder\": \"{$raw}\" holds ".count($photos).' images — at most '.PhotoArchive::MAX_FILES_PER_FOLDER.' per folder.';

            return null;
        }

        foreach ($photos as $photo) {
            if ($photo['size'] > PhotoArchive::MAX_FILE_BYTES) {
                $megabytes = round(PhotoArchive::MAX_FILE_BYTES / 1024 / 1024);
                $errors[] = "Column \"photo_folder\": \"{$photo['name']}\" is larger than {$megabytes} MB. Please shrink it.";

                return null;
            }
        }

        return $photos;
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
                $row->errors[] = "The id \"{$id}\" is not a number. Never fill the id column in by hand.";

                return null;
            }

            $id = (int) $id;

            if (isset($idsInFile[$id])) {
                $row->errors[] = "Id {$id} is already used in row {$idsInFile[$id]}.";

                return null;
            }

            $listing = Listing::find($id);

            if ($listing === null) {
                $row->errors[] = "There is no listing with id {$id}.";

                return null;
            }

            $idsInFile[$id] = $row->line;
            $row->listingId = $id;

            return $listing;
        }

        $name = trim($values['name'] ?? '');
        $slug = trim($values['slug'] ?? '');

        if ($name === '' && $slug === '') {
            $row->errors[] = 'A row without an id needs a name.';

            return null;
        }

        $slug = $slug !== '' ? Str::slug($slug) : Str::slug($name);

        if (isset($slugsInFile[$slug])) {
            $row->errors[] = "This name produces the same URL (slug \"{$slug}\") as row {$slugsInFile[$slug]}.";

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
            $ids = implode(' or ', $existingIds);
            $row->errors[] = count($existingIds) > 1
                ? "\"{$name}\" already exists more than once (id {$ids}). Put the right id in the id column."
                : "\"{$name}\" already exists (id {$ids}). To update it, put {$ids} in the id column — or change the name if this is a different business.";

            return null;
        }

        $slugsInFile[$slug] = $row->line;
        $row->isNew = true;

        if ($similar = $this->findSimilarListing($slug)) {
            $row->warnings[] = "A similar name already exists: \"{$similar['slug']}\" (id {$similar['id']}). If this is the same business, put that id in the id column instead.";
        }

        return null;
    }

    private function applyCell(PlannedRow $row, ?Listing $listing, SheetColumn $column, string $raw): void
    {
        $clear = ListingSheet::isClearMarker($raw);

        if ($clear && $column->requiredForNew) {
            $row->errors[] = "Column \"{$column->header}\" cannot be emptied.";

            return;
        }

        /** @var array<string, mixed>|null $parsed attribute => value, or null on a parse error */
        $parsed = $clear
            ? $this->parser->cleared($column)
            : $this->parser->parse($column, $raw, $row->errors);

        if ($parsed === null) {
            return;
        }

        $display = $clear ? null : $this->parser->display($column, $parsed);
        $old = $listing !== null ? $this->parser->asString(ListingSheet::cellValue($column, $listing)) : null;

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
     * @param  array<string, mixed>  $parsed
     */
    private function differs(SheetColumn $column, Listing $listing, array $parsed): bool
    {
        $locale = ListingSheet::locale();

        return match ($column->type) {
            SheetColumnType::Coordinates => ListingSheet::formatCoordinates($listing->latitude, $listing->longitude)
                !== ListingSheet::formatCoordinates($parsed['latitude'] ?? null, $parsed['longitude'] ?? null),
            SheetColumnType::Decimal => $this->parser->numberOrNull($listing->getAttribute($column->attribute)) !== $this->parser->numberOrNull($parsed[$column->attribute] ?? null),
            SheetColumnType::Integer => $this->parser->intOrNull($listing->getAttribute($column->attribute)) !== $this->parser->intOrNull($parsed[$column->attribute] ?? null),
            SheetColumnType::Boolean => (bool) $listing->getAttribute($column->attribute) !== (bool) ($parsed[$column->attribute] ?? false),
            SheetColumnType::City => $listing->city_id !== ($parsed['city_id'] ?? null),
            SheetColumnType::ListingTypeEnum, SheetColumnType::VehicleCategoryEnum => $listing->getAttribute($column->attribute) !== ($parsed[$column->attribute] ?? null),
            SheetColumnType::Translatable => (string) $listing->getTranslation($column->attribute, $locale, false) !== (string) ($parsed[$column->attribute] ?? ''),
            SheetColumnType::RichText => (string) ListingSheet::toPlainText($listing->getTranslation($column->attribute, $locale, false)) !== (string) ($parsed[$column->attribute] ?? ''),
            default => (string) $listing->getAttribute($column->attribute) !== (string) ($parsed[$column->attribute] ?? ''),
        };
    }

    /**
     * The BookableUnits sheet. A CSV has no second sheet, so this is simply empty for one.
     *
     * @param  list<PlannedRow>  $listingRows
     * @return list<PlannedRoomRow>
     */
    private function planRoomRows(string $path, array $listingRows, ?PhotoArchive $archive): array
    {
        $file = $this->reader->read($path, BookableUnitSheet::SHEET_NAME);

        if ($file['rows'] === []) {
            return [];
        }

        /** @var array<int, SheetColumn> $map */
        $map = [];

        foreach ($file['headers'] as $index => $header) {
            $column = $header === '' ? null : BookableUnitSheet::findColumn($header);

            if ($column !== null) {
                $map[$index] = $column;
            }
        }

        $rows = [];
        $seen = [];

        foreach ($file['rows'] as $raw) {
            $values = [];

            foreach ($map as $index => $column) {
                $values[$column->header] = $raw['cells'][$index] ?? '';
            }

            $rows[] = $this->planRoomRow($raw['line'], $values, $listingRows, $seen, $archive);
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<PlannedRow>  $listingRows
     * @param  array<string, int>  $seen
     */
    private function planRoomRow(int $line, array $values, array $listingRows, array &$seen, ?PhotoArchive $archive): PlannedRoomRow
    {
        $row = new PlannedRoomRow($line);
        $row->code = trim($values['code'] ?? '');
        $row->listingName = trim($values['listing'] ?? '');

        if ($row->code === '') {
            $row->errors[] = 'A room type needs a code — it is what matches the row to a room.';

            return $row;
        }

        $existing = $this->resolveRoomListing($row, $values, $listingRows);

        if (! $row->isValid()) {
            return $row;
        }

        $key = ($row->listingId ?? spl_object_id($row->listingRow ?? $row)).'|'.ListingSheet::normalize($row->code);

        if (isset($seen[$key])) {
            $row->errors[] = "The same listing and code already appear in row {$seen[$key]}.";

            return $row;
        }

        $seen[$key] = $line;

        $bookableUnit = $existing;
        $row->isNew = $bookableUnit === null;
        $row->bookableUnitId = $bookableUnit?->id;

        foreach (BookableUnitSheet::columns() as $column) {
            if ($column->type === SheetColumnType::Id || $column->attribute === '' || ! array_key_exists($column->header, $values)) {
                continue;
            }

            $raw = $values[$column->header];

            if ($raw === '') {
                if ($row->isNew && $column->requiredForNew) {
                    $row->errors[] = "Column \"{$column->header}\" is required on new room types.";
                }

                continue;
            }

            if ($column->type === SheetColumnType::PhotoFolder) {
                $this->planRoomPhotos($row, $bookableUnit, $raw, $archive);

                continue;
            }

            $clear = ListingSheet::isClearMarker($raw);

            if ($clear && $column->requiredForNew) {
                $row->errors[] = "Column \"{$column->header}\" cannot be emptied.";

                continue;
            }

            $parsed = $clear
                ? $this->parser->cleared($column)
                : $this->parser->parse($column, $raw, $row->errors);

            if ($parsed === null) {
                continue;
            }

            $value = $parsed[$column->attribute] ?? null;
            $old = $bookableUnit !== null ? $this->parser->asString(BookableUnitSheet::cellValue($column, $bookableUnit)) : null;
            $new = $clear ? null : $this->parser->display($column, $parsed);

            if ($bookableUnit !== null && $old === $new) {
                continue;
            }

            $row->changes[] = new FieldChange($column->header, $old, $new);
            $row->attributes[$column->attribute] = $value;
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<PlannedRow>  $listingRows
     */
    private function resolveRoomListing(PlannedRoomRow $row, array $values, array $listingRows): ?BookableUnit
    {
        $id = trim($values['listing_id'] ?? '');

        if ($id !== '') {
            if (! ctype_digit($id)) {
                $row->errors[] = "The listing_id \"{$id}\" is not a number.";

                return null;
            }

            $listing = Listing::find((int) $id);

            if ($listing === null) {
                $row->errors[] = "There is no listing with id {$id}.";

                return null;
            }

            $row->listingId = $listing->id;

            if ($row->listingName === '') {
                $row->listingName = (string) $listing->getTranslation('name', ListingSheet::locale(), false);
            }

            return BookableUnit::where('listing_id', $listing->id)->where('code', $row->code)->first();
        }

        if ($row->listingName === '') {
            $row->errors[] = 'A room type row needs either a listing_id or a listing name.';

            return null;
        }

        $normalized = ListingSheet::normalize($row->listingName);
        $ids = $this->idsByName[$normalized] ?? [];

        if (count($ids) > 1) {
            $row->errors[] = "\"{$row->listingName}\" matches several listings (id ".implode(', ', $ids).'). Use listing_id instead.';

            return null;
        }

        if ($ids !== []) {
            $row->listingId = $ids[0];

            return BookableUnit::where('listing_id', $ids[0])->where('code', $row->code)->first();
        }

        // Not in the database yet — but it may be a listing this very import creates,
        // which is what lets someone capture a lodge and its rooms in one file.
        foreach ($listingRows as $listingRow) {
            if ($listingRow->isNew && $listingRow->isValid() && ListingSheet::normalize($listingRow->name) === $normalized) {
                $row->listingRow = $listingRow;

                return null;
            }
        }

        $row->errors[] = "There is no listing called \"{$row->listingName}\" — check the spelling, or add it on the Listings sheet of this same file.";

        return null;
    }

    private function storePhotos(Listing $listing, PhotoArchive $archive, string $folder): void
    {
        $keys = $this->uploadPhotos($archive, $folder, "listings/{$listing->slug}");

        if ($keys === []) {
            return;
        }

        $listing->forceFill([
            'image' => array_shift($keys),
            'gallery' => $keys,
            'photos_source' => ContentSource::Partner,
            'photos_approved_at' => now(),
        ])->save();
    }

    /**
     * Copies a folder's images to R2 under the given prefix, cover first.
     *
     * The storage key is built from the prefix and the file's own name, never from
     * the path inside the archive — see PhotoArchive.
     *
     * @return list<string> the stored keys
     */
    private function uploadPhotos(PhotoArchive $archive, string $folder, string $prefix): array
    {
        $disk = Storage::disk('r2');
        $keys = [];

        foreach ($archive->photos($folder) as $photo) {
            $contents = $archive->contents($photo['index']);

            if ($contents === null) {
                continue;
            }

            $name = Str::slug(pathinfo($photo['name'], PATHINFO_FILENAME));
            $extension = mb_strtolower(pathinfo($photo['name'], PATHINFO_EXTENSION));
            $key = "{$prefix}/{$name}-".substr(md5($contents), 0, 8).".{$extension}";

            $disk->put($key, $contents);
            $keys[] = $key;
        }

        return $keys;
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
        $this->slugsToId = [];
        $this->idsByName = [];

        // Hydrated rather than plucked: `name` is a translatable JSON column, so the
        // readable value only exists on the model.
        Listing::query()->select(['id', 'name', 'slug'])->get()->each(function (Listing $listing): void {
            $this->slugsToId[(string) $listing->slug] = $listing->id;
            $this->idsByName[ListingSheet::normalize((string) $listing->name)][] = $listing->id;
        });
    }
}
