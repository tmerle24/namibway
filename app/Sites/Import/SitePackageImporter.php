<?php

namespace App\Sites\Import;

use App\Enums\BusinessType;
use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Filament\Support\SiteResolver;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Services\ImportExport\ImportPlan;
use App\Services\ImportExport\ListingImporter;
use App\Sites\ActionButtons;
use App\Sites\BlockRegistry;
use App\Sites\Generation\SiteGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns a website package into a partner, a listing and a site — creating
 * what does not exist, updating what does.
 *
 * Always in two steps, like the Excel import: plan() reads the package and
 * says what would change without writing anything; apply() re-plans the same
 * package and writes it. Format and matching rules: SITE_PACKAGE.md.
 *
 * Platform listings ride along in the same ZIP as `listings.csv` (plus their
 * photographs under `listings/`), and are handed to the Excel importer that
 * already owns that table — one sheet definition, one set of rules, one write
 * path. See SITE_PACKAGE.md.
 *
 * What it never does: delete. A field the package leaves out is left alone,
 * a picture already on the site stays, and a band the package does not name
 * keeps its content and moves below the ones it does.
 */
class SitePackageImporter
{
    /** Columns a package may write, per entity — the JSON uses the column names. */
    private const PARTNER_FIELDS = ['name', 'email', 'phone', 'address', 'latitude', 'longitude', 'website', 'social_links', 'short_description', 'bio'];

    private const LISTING_FIELDS = ['name', 'contact_email', 'phone', 'address', 'latitude', 'longitude', 'website', 'social_links', 'short_description', 'description'];

    private const SITE_FIELDS = ['name', 'accent', 'contact_email', 'contact_phone', 'whatsapp', 'address', 'latitude', 'longitude', 'social_links', 'logo_hero_height', 'logo_compact_height', 'logo_shadow', 'action_buttons'];

    /** Same bounds as EditSiteLogoAction. */
    private const LOGO_HEIGHTS = ['logo_hero_height' => [32, 300], 'logo_compact_height' => [24, 120]];

    private const PAGE_FIELDS = ['title', 'meta_description'];

    public function plan(SitePackage $package): SitePackagePlan
    {
        $plan = new SitePackagePlan;
        $plan->errors = $package->errors();
        $m = $package->manifest();

        if ($m === []) {
            return $plan;
        }

        [$site, $partner, $listing] = $this->match($m, $plan);

        $plan->partner = $this->entityPlan('Partner', $partner, $partner->name ?? ($m['partner']['name'] ?? null),
            $this->changes($partner, $this->section($m, 'partner', self::PARTNER_FIELDS)));

        if ($partner === null && blank($m['partner']['name'] ?? null)) {
            $plan->errors[] = 'No partner matches this package, and "partner.name" is missing to create one.';
        }

        if (isset($m['listing'])) {
            $plan->listing = $this->entityPlan('Listing', $listing, $listing->name ?? ($m['listing']['name'] ?? $m['partner']['name'] ?? null),
                $this->changes($listing, $this->section($m, 'listing', self::LISTING_FIELDS)));

            if ($listing === null && ListingType::tryFrom((string) ($m['listing']['type'] ?? '')) === null) {
                $plan->errors[] = 'A new listing needs "listing.type" (accommodation, activity, restaurant or vehicle).';
            }
        }

        $siteChanges = $this->changes($site, $this->section($m, 'site', self::SITE_FIELDS));
        $page = $site?->pages()->where('is_home', true)->first();

        foreach ($this->section($m, 'site', self::PAGE_FIELDS) as $field => $value) {
            if ($this->differs($page?->{$field}, $value)) {
                $siteChanges[] = ['field' => 'page '.$field, 'old' => $this->show($page?->{$field}), 'new' => $this->show($value)];
            }
        }

        $logo = trim((string) ($m['site']['logo'] ?? ''));

        if ($logo !== '') {
            if (! $package->has($logo) || ! $package->isImage($logo)) {
                $plan->errors[] = "The logo [{$logo}] is named in site.json but is not an image in the ZIP.";
            } elseif ($site === null || $site->logo_key !== $this->imageKey($site, $logo)) {
                $siteChanges[] = ['field' => 'logo', 'old' => $this->show($site?->logo_key), 'new' => $logo];
            }
        }

        if (isset($m['site']['logo_shadow']) && ! in_array($m['site']['logo_shadow'], ['glow', 'shadow', 'none'], true)) {
            $plan->errors[] = '"site.logo_shadow" must be glow, shadow or none.';
        }

        foreach (self::LOGO_HEIGHTS as $field => [$min, $max]) {
            $value = $m['site'][$field] ?? null;

            if ($value !== null && (! is_int($value) || $value < $min || $value > $max)) {
                $plan->errors[] = "\"site.{$field}\" must be a whole number of pixels between {$min} and {$max}.";
            }
        }

        $plan->site = $this->entityPlan('Website', $site, $site->name ?? ($m['site']['name'] ?? $m['partner']['name'] ?? null), $siteChanges);

        if ($site === null && ! isset($m['listing']) && BusinessType::tryFrom((string) ($m['site']['business_type'] ?? '')) === null) {
            $plan->errors[] = 'A new website without a listing needs "site.business_type" ('
                .implode(', ', array_map(fn (BusinessType $t) => $t->value, BusinessType::cases())).').';
        }

        $this->planMedia($package, $m, $site, $plan);
        $this->planBlocks($m, $page, $plan);
        $this->planListings($package, $partner, $plan);

        return $plan;
    }

    /**
     * Writes the package. Re-plans first and refuses a plan with errors, so
     * nothing a person did not see in the preview can be written.
     */
    public function apply(SitePackage $package): SitePackagePlan
    {
        $plan = $this->plan($package);

        if ($plan->hasErrors()) {
            return $plan;
        }

        $m = $package->manifest();
        $noop = new SitePackagePlan;

        [$site, $partner, $listing] = DB::transaction(function () use ($m, $noop): array {
            [$site, $partner, $listing] = $this->match($m, $noop);

            $partner ??= new Partner;
            $this->write($partner, $this->section($m, 'partner', self::PARTNER_FIELDS), ['bio']);

            if (isset($m['listing'])) {
                if ($listing === null) {
                    $listing = new Listing([
                        'partner_id' => $partner->id,
                        'type' => ListingType::from((string) $m['listing']['type']),
                        'vehicle_category' => VehicleCategory::tryFrom((string) ($m['listing']['vehicle_category'] ?? '')),
                        'is_published' => false,
                    ]);

                    // A new listing starts from the partner's facts.
                    $listing->name = $partner->name;
                    $listing->contact_email = $partner->email;
                    $listing->phone = $partner->phone;
                    $listing->address = $partner->address;
                    $listing->latitude = $partner->latitude;
                    $listing->longitude = $partner->longitude;
                    $listing->website = $partner->website;
                    $listing->social_links = $partner->social_links;

                    $listing->slug = $this->uniqueListingSlug((string) ($m['listing']['name'] ?? $partner->name));
                }

                $this->write($listing, $this->section($m, 'listing', self::LISTING_FIELDS), ['name', 'description', 'short_description']);
            }

            return [$site, $partner, $listing];
        });

        if ($site === null) {
            $generator = app(SiteGenerator::class);
            $site = $listing !== null
                ? $generator->fromListing($listing)
                : $generator->empty(
                    (string) ($m['site']['name'] ?? $partner->name),
                    BusinessType::from((string) $m['site']['business_type']),
                    $partner,
                );
        }

        // Media first and outside the transaction: an upload that fails halfway
        // leaves only objects under the site's prefix, and a re-run overwrites
        // them under the same keys.
        $keys = $this->upload($package, $m, $site);

        DB::transaction(function () use ($m, $site, $keys): void {
            $fields = $this->section($m, 'site', self::SITE_FIELDS);

            if (BusinessType::tryFrom((string) ($m['site']['business_type'] ?? '')) !== null) {
                $fields['business_type'] = BusinessType::from((string) $m['site']['business_type']);
            }

            $logo = trim((string) ($m['site']['logo'] ?? ''));

            if ($logo !== '') {
                $fields['logo_key'] = $keys[strtolower($logo)];
            }

            // Only the places and labels ActionButtons knows survive.
            if (isset($fields['action_buttons'])) {
                $fields['action_buttons'] = ActionButtons::normalise($fields['action_buttons']);
            }

            // "glow" is the default, stored as null (see EditSiteLogoAction).
            if (($fields['logo_shadow'] ?? null) === 'glow') {
                $fields['logo_shadow'] = null;
            }

            $this->write($site, $fields, []);

            $page = $site->pages()->where('is_home', true)->first()
                ?? SitePage::create(['site_id' => $site->id, 'is_home' => true, 'slug' => '', 'locale' => $site->default_locale, 'title' => $site->name]);

            $pageFields = $this->section($m, 'site', self::PAGE_FIELDS);

            if ($pageFields !== []) {
                $page->update($pageFields);
            }

            $ids = $this->writeImages($m, $site, $keys);
            $this->writeBlocks($m, $page, $ids, $keys);
        });

        $this->withListings($package, $partner, function (ImportPlan $listings) use ($package, $partner, $plan): void {
            $plan->listingsWritten = app(ListingImporter::class)->apply($listings, $package->path);
            $this->attachToPartner($package, $partner);
        });

        $plan->written = true;
        $plan->siteId = $site->id;

        return $plan;
    }

    /**
     * The listings sheet, planned by the importer that owns listings. Its
     * problems are this package's problems: a ZIP is imported as one thing, so
     * a bad row stops the website too rather than half-writing the customer.
     */
    private function planListings(SitePackage $package, ?Partner $partner, SitePackagePlan $plan): void
    {
        $this->withListings($package, $partner, function (ImportPlan $listings) use ($plan): void {
            $plan->listingsNew = $listings->newCount();
            $plan->listingsUpdated = $listings->updateCount();

            foreach ($listings->fileErrors as $error) {
                $plan->errors[] = 'listings.csv: '.$error;
            }

            foreach ($listings->invalidRows() as $row) {
                foreach ($row->errors as $message) {
                    $plan->errors[] = 'listings.csv row '.$row->line.' ('.$row->name.'): '.$message;
                }
            }
        });
    }

    /**
     * Runs $then against a freshly planned listings sheet, if the package has
     * one. The sheet is written to a temporary file because the importer reads
     * from disk; the photographs it needs are read straight out of the ZIP.
     *
     * @param  callable(ImportPlan): void  $then
     */
    private function withListings(SitePackage $package, ?Partner $partner, callable $then): void
    {
        $csv = $package->listingsCsv();

        if ($csv === null) {
            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'listings').'.csv';
        file_put_contents($path, $this->withResolvedIds($csv, $partner));

        try {
            $then(app(ListingImporter::class)->plan($path, $package->path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * The listings sheet has no partner column — it was written for bulk
     * capture, where somebody assigns the partner afterwards. In a package
     * there is nothing to assign: the listings belong to the business the
     * package is about, and without that they would sit in nobody's panel and
     * be found by no second import. Only listings that have no partner yet are
     * claimed, so a package can never take one off another business.
     */
    private function attachToPartner(SitePackage $package, Partner $partner): void
    {
        $slugs = $this->sheetSlugs((string) $package->listingsCsv());

        if ($slugs === []) {
            return;
        }

        Listing::whereIn('slug', $slugs)->whereNull('partner_id')->update(['partner_id' => $partner->id]);
    }

    /**
     * @return list<string>
     */
    private function sheetSlugs(string $csv): array
    {
        $rows = $this->readCsv($csv);

        if ($rows === []) {
            return [];
        }

        $headers = array_map(fn ($h): string => Str::lower(trim((string) $h)), $rows[0]);
        $slugAt = array_search('slug', $headers, true);
        $nameAt = array_search('name', $headers, true);
        $slugs = [];

        foreach (array_slice($rows, 1) as $row) {
            $slug = Str::slug((string) ($slugAt !== false ? ($row[$slugAt] ?? '') : ''))
                ?: Str::slug((string) ($nameAt !== false ? ($row[$nameAt] ?? '') : ''));

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Fills the sheet's `id` column from the slug each row names.
     *
     * The listings importer takes `id` as its only update key on purpose: a
     * sheet typed by a person must never silently overwrite a listing that
     * happens to share a name. A package is not that sheet — it is written by
     * machine and describes one customer completely, so re-importing it has to
     * update the same listings rather than stop and ask for numbers nobody has.
     * Resolving the slug here keeps both true: the rule stands, and the package
     * arrives with the ids already in it.
     *
     * Only the matched partner's own listings are resolved. A slug belonging to
     * somebody else stays unresolved, and the importer then reports it as the
     * collision it is instead of writing across two businesses.
     */
    private function withResolvedIds(string $csv, ?Partner $partner): string
    {
        if ($partner === null || ! $partner->exists) {
            return $csv;
        }

        $rows = $this->readCsv($csv);

        if ($rows === []) {
            return $csv;
        }

        $headers = array_map(fn ($h): string => Str::lower(trim((string) $h)), $rows[0]);
        $slugAt = array_search('slug', $headers, true);
        $nameAt = array_search('name', $headers, true);
        $idAt = array_search('id', $headers, true);

        if ($slugAt === false && $nameAt === false) {
            return $csv;
        }

        if ($idAt === false) {
            $headers[] = 'id';
            $rows[0][] = 'id';
            $idAt = count($rows[0]) - 1;
        }

        $known = Listing::where('partner_id', $partner->id)->pluck('id', 'slug');
        $width = count($rows[0]);
        $out = [$rows[0]];

        foreach (array_slice($rows, 1) as $row) {
            $row = array_pad($row, $width, '');

            if (trim($row[$idAt] ?? '') === '') {
                $slug = Str::slug($slugAt !== false ? ($row[$slugAt] ?? '') : '')
                    ?: Str::slug($nameAt !== false ? ($row[$nameAt] ?? '') : '');

                $row[$idAt] = $slug !== '' && $known->has($slug) ? (string) $known[$slug] : '';
            }

            $out[] = array_values($row);
        }

        return $this->writeCsv($out);
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        // fgetcsv rather than splitting on lines: a description holds newlines.
        while (($row = fgetcsv($handle)) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(fn ($cell): string => (string) $cell, $row);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function writeCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Who this package is about. The site slug is the strongest key — a
     * package built from an existing site carries it — then the partner's
     * email, then its exact name. Two partners answering to one name is an
     * error, never a pick.
     *
     * @param  array<string, mixed>  $m
     * @return array{0: ?Site, 1: ?Partner, 2: ?Listing}
     */
    private function match(array $m, SitePackagePlan $plan): array
    {
        $slug = trim((string) ($m['site']['slug'] ?? ''));
        $site = $slug !== '' ? Site::where('slug', $slug)->first() : null;

        if ($slug !== '' && $site === null) {
            $plan->warnings[] = "No website has the slug [{$slug}] — a new one is created (its slug comes from the name).";
        }

        $partner = $site?->partner;
        $listing = $site?->sourceListing;

        if ($partner === null && filled($m['partner']['email'] ?? null)) {
            $partner = Partner::whereRaw('lower(email) = ?', [Str::lower(trim((string) $m['partner']['email']))])->first();
        }

        if ($partner === null && filled($m['partner']['name'] ?? null)) {
            $named = Partner::whereRaw('lower(name) = ?', [Str::lower(trim((string) $m['partner']['name']))])->limit(2)->get();

            if ($named->count() > 1) {
                $plan->errors[] = 'Two partners are called "'.$m['partner']['name'].'" — add "site.slug" or "partner.email" to say which.';
            }

            $partner = $named->count() === 1 ? $named->first() : null;
        }

        if ($listing === null && filled($m['listing']['slug'] ?? null)) {
            $listing = Listing::where('slug', (string) $m['listing']['slug'])->first();
        }

        // The partner's own listing: the only one, or the one with this name.
        if ($listing === null && $partner !== null && isset($m['listing'])) {
            $own = $partner->listings()->get();
            $name = Str::lower(trim((string) ($m['listing']['name'] ?? $partner->name)));
            $named = $own->filter(fn (Listing $l): bool => Str::lower(trim((string) $l->name)) === $name);

            $listing = match (true) {
                $own->count() === 1 => $own->first(),
                $named->count() === 1 => $named->first(),
                default => null,
            };

            if ($listing === null && $own->count() > 1) {
                $plan->errors[] = 'The partner has '.$own->count().' listings — add "listing.slug" to say which one this website belongs to.';
            }
        }

        if ($listing !== null && $partner !== null && $listing->partner_id !== $partner->id) {
            $plan->errors[] = 'The listing ['.$listing->slug.'] belongs to a different partner than the one this package matched.';
        }

        if ($site === null) {
            $site = $listing !== null ? SiteResolver::for($listing) : ($partner !== null ? SiteResolver::for($partner) : null);
        }

        return [$site, $partner, $listing];
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function planMedia(SitePackage $package, array $m, ?Site $site, SitePackagePlan $plan): void
    {
        foreach ($this->imageFiles($m) as $file => $alt) {
            if (! $package->has($file) || ! $package->isImage($file)) {
                $plan->errors[] = "The picture [{$file}] is named in site.json but is not an image in the ZIP.";

                continue;
            }

            $exists = $site !== null && $site->images()->where('key', $this->imageKey($site, $file))->exists();
            $exists ? $plan->imagesUpdated++ : $plan->imagesNew++;
        }

        foreach ($this->videoFiles($m) as $file) {
            if (! $package->has($file) || ! $package->isVideo($file)) {
                $plan->errors[] = "The video [{$file}] is named in site.json but is not a video in the ZIP.";

                continue;
            }

            $plan->videos++;
        }

        $named = array_map('strtolower', [...array_keys($this->imageFiles($m)), ...$this->videoFiles($m), (string) ($m['site']['logo'] ?? '')]);
        $unused = array_diff(array_map('strtolower', $package->mediaFiles()), $named);

        if ($unused !== []) {
            $plan->warnings[] = count($unused).' file(s) in the ZIP are not used by site.json and are skipped: '.implode(', ', array_slice($unused, 0, 8)).(count($unused) > 8 ? ', …' : '');
        }
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function planBlocks(array $m, ?SitePage $page, SitePackagePlan $plan): void
    {
        $seen = [];

        foreach ((array) ($m['blocks'] ?? []) as $i => $entry) {
            $type = is_array($entry) ? (string) ($entry['type'] ?? '') : '';
            $definition = BlockRegistry::find($type);

            if ($definition === null) {
                $plan->errors[] = 'Block '.($i + 1).": [{$type}] is not a block type.";

                continue;
            }

            if (in_array($type, $seen, true)) {
                $plan->errors[] = "The [{$type}] band appears twice — a page carries one of each.";

                continue;
            }

            $seen[] = $type;
            $existing = $page?->blocks()->where('type', $type)->first();

            if (! array_key_exists('data', $entry)) {
                $plan->blocks[] = ['type' => $type, 'label' => $definition->label(), 'action' => $existing ? 'kept, placed here' : 'created empty'];

                continue;
            }

            // Placeholder ids: validation only needs to know the shape.
            $data = $this->resolveRefs((array) $entry['data'], fn (): int => 0, fn (string $f): string => 'videos/'.$f);
            $validator = Validator::make($data, $definition->rules());

            foreach ($validator->errors()->all() as $message) {
                $plan->errors[] = "The [{$type}] band: {$message}";
            }

            $plan->blocks[] = ['type' => $type, 'label' => $definition->label(), 'action' => $existing ? 'replaced' : 'created'];
        }

        if ($page !== null) {
            foreach ($page->blocks()->orderBy('sort')->get() as $block) {
                if (! in_array($block->type, $seen, true)) {
                    $plan->blocks[] = ['type' => $block->type, 'label' => $block->definition()?->label() ?? $block->type, 'action' => 'untouched, moved below'];
                }
            }
        }
    }

    /**
     * Every picture the package uses, with its alt text: the `images` list
     * first (in its order), then anything a block names that the list forgot.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, ?string> file => alt
     */
    private function imageFiles(array $m): array
    {
        $files = [];

        foreach ((array) ($m['images'] ?? []) as $image) {
            $file = is_array($image) ? (string) ($image['file'] ?? '') : (string) $image;

            if ($file !== '') {
                $files[$file] = is_array($image) && filled($image['alt'] ?? null) ? (string) $image['alt'] : null;
            }
        }

        foreach ((array) ($m['blocks'] ?? []) as $entry) {
            $this->resolveRefs((array) ($entry['data'] ?? []), function (string $file) use (&$files): int {
                $files[$file] ??= null;

                return 0;
            }, fn (string $f): string => $f);
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<string>
     */
    private function videoFiles(array $m): array
    {
        $files = [];

        foreach ((array) ($m['blocks'] ?? []) as $entry) {
            $this->resolveRefs((array) ($entry['data'] ?? []), fn (): int => 0, function (string $file) use (&$files): string {
                $files[] = $file;

                return $file;
            });
        }

        return array_values(array_unique($files));
    }

    /**
     * File names in a block's data become what the block stores: `image` →
     * `image_id`, `images` → `image_ids`, and per item `image` → `image_id`,
     * `poster` → `poster_image_id`, `video` → `key`.
     *
     * @param  array<string, mixed>  $data
     * @param  callable(string): int  $image
     * @param  callable(string): string  $video
     * @return array<string, mixed>
     */
    private function resolveRefs(array $data, callable $image, callable $video): array
    {
        if (array_key_exists('image', $data)) {
            $data['image_id'] = filled($data['image']) ? $image((string) $data['image']) : null;
            unset($data['image']);
        }

        if (array_key_exists('images', $data)) {
            $data['image_ids'] = array_map(fn ($f): int => $image((string) $f), array_values((array) $data['images']));
            unset($data['images']);
        }

        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $i => $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['image' => 'image_id', 'poster' => 'poster_image_id'] as $from => $to) {
                    if (array_key_exists($from, $item)) {
                        $item[$to] = filled($item[$from]) ? $image((string) $item[$from]) : null;
                        unset($item[$from]);
                    }
                }

                if (array_key_exists('video', $item)) {
                    $item['key'] = $video((string) $item['video']);
                    unset($item['video']);
                }

                $data['items'][$i] = $item;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, string> lowercased file => bucket key
     */
    private function upload(SitePackage $package, array $m, Site $site): array
    {
        $disk = Storage::disk('r2');
        $keys = [];

        foreach (array_keys($this->imageFiles($m)) as $file) {
            $key = $this->imageKey($site, $file);
            $disk->put($key, $package->contents($file));
            $keys[strtolower($file)] = $key;
        }

        $logo = trim((string) ($m['site']['logo'] ?? ''));

        if ($logo !== '') {
            $key = $this->imageKey($site, $logo);
            $disk->put($key, $package->contents($logo));
            $keys[strtolower($logo)] = $key;
        }

        foreach ($this->videoFiles($m) as $file) {
            $key = $site->mediaPrefix().'/videos/'.$this->safeName($file);
            $disk->put($key, $package->contents($file), ['ContentType' => $this->videoMime($file)]);
            $keys[strtolower($file)] = $key;
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, string>  $keys
     * @return array<string, int> lowercased file => site_images.id
     */
    private function writeImages(array $m, Site $site, array $keys): array
    {
        $ids = [];
        $sort = (int) $site->images()->max('sort') + 1;

        foreach ($this->imageFiles($m) as $file => $alt) {
            $key = $keys[strtolower($file)];
            $image = $site->images()->where('key', $key)->first();

            if ($image === null) {
                $image = SiteImage::create([
                    'site_id' => $site->id,
                    'key' => $key,
                    'alt' => $alt,
                    // Supplied by or for the business: top of the ladder.
                    'content_source' => ContentSource::Partner,
                    'prospect_only' => false,
                    'sort' => $sort++,
                ]);
            } elseif ($alt !== null && $image->alt !== $alt) {
                $image->update(['alt' => $alt]);
            }

            $ids[strtolower($file)] = $image->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, int>  $ids
     * @param  array<string, string>  $keys
     */
    private function writeBlocks(array $m, SitePage $page, array $ids, array $keys): void
    {
        $seen = [];
        $sort = 0;

        foreach ((array) ($m['blocks'] ?? []) as $entry) {
            $type = (string) $entry['type'];
            $block = $page->blocks()->firstOrNew(['type' => $type]);
            $fill = ['sort' => $sort++, 'is_enabled' => (bool) ($entry['enabled'] ?? true)];

            if (array_key_exists('data', $entry)) {
                $fill['data'] = $this->resolveRefs(
                    (array) $entry['data'],
                    fn (string $f): int => $ids[strtolower($f)],
                    fn (string $f): string => $keys[strtolower($f)],
                );
            } elseif (! $block->exists) {
                $fill['data'] = $block->definition()?->defaults() ?? [];
            }

            $block->fill($fill)->save();
            $seen[] = $type;
        }

        foreach ($page->blocks()->whereNotIn('type', $seen === [] ? [''] : $seen)->orderBy('sort')->get() as $block) {
            $block->update(['sort' => $sort++]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $translatable
     */
    private function write(Listing|Partner|Site $model, array $values, array $translatable): void
    {
        foreach ($values as $field => $value) {
            if (in_array($field, $translatable, true) && ($model instanceof Listing || $model instanceof Partner)) {
                $model->setTranslation($field, 'en', $value);
            } else {
                $model->{$field} = $value;
            }
        }

        $model->save();
    }

    /**
     * The fields of one section that the package actually sets.
     *
     * @param  array<string, mixed>  $m
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function section(array $m, string $name, array $allowed): array
    {
        $section = (array) ($m[$name] ?? []);

        return array_filter(
            array_intersect_key($section, array_flip($allowed)),
            fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{field: string, old: string, new: string}>
     */
    private function changes(Listing|Partner|Site|null $model, array $values): array
    {
        $changes = [];

        foreach ($values as $field => $value) {
            $old = $model === null ? null : $this->current($model, $field);

            // Stored normalised — every button with its places — while a
            // package names only the buttons it moves. Compared as what would
            // be stored, so an unchanged placement is not reported as a change.
            if ($field === 'action_buttons') {
                $old = ActionButtons::normalise($old);
                $value = ActionButtons::normalise($value);
            }

            if ($model === null || $this->differs($old, $value)) {
                $changes[] = ['field' => $field, 'old' => $this->show($old), 'new' => $this->show($value)];
            }
        }

        return $changes;
    }

    private function current(Listing|Partner|Site $model, string $field): mixed
    {
        if (($model instanceof Listing || $model instanceof Partner) && $model->isTranslatableAttribute($field)) {
            return $model->getTranslation($field, 'en', false);
        }

        $value = $model->{$field};

        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function differs(mixed $old, mixed $new): bool
    {
        if (is_numeric($old) && is_numeric($new)) {
            return abs((float) $old - (float) $new) > 1e-7;
        }

        return $this->show($old) !== $this->show($new);
    }

    private function show(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return trim((string) $value);
    }

    /**
     * @param  list<array{field: string, old: string, new: string}>  $changes
     * @return array{action: string, label: ?string, id: ?int, changes: list<array{field: string, old: string, new: string}>}
     */
    private function entityPlan(string $kind, Listing|Partner|Site|null $model, mixed $label, array $changes): array
    {
        return [
            'kind' => $kind,
            'action' => $model === null ? 'create' : ($changes === [] ? 'unchanged' : 'update'),
            'label' => $label === null ? null : (string) $label,
            'id' => $model?->id,
            'changes' => $changes,
        ];
    }

    private function uniqueListingSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'listing';
        $slug = $base;
        $n = 2;

        while (Listing::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function imageKey(Site $site, string $file): string
    {
        return $site->mediaPrefix().'/'.$this->safeName($file);
    }

    private function safeName(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return Str::slug(pathinfo($file, PATHINFO_FILENAME)).'.'.$extension;
    }

    private function videoMime(string $file): string
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'video/mp4',
        };
    }
}
