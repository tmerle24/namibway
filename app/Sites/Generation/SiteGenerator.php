<?php

namespace App\Sites\Generation;

use App\Enums\BusinessType;
use App\Enums\SiteStatus;
use App\Enums\VehicleCategory;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Builds a website — from a listing, or from nothing.
 *
 * Both entry points produce the same structures. A customer with no listing is
 * not a degraded case: half the businesses on the flyer are a workshop or a
 * shop that will never be on the travel platform, and a second code path for
 * them would be twice the product for the same money.
 *
 * ## Re-running
 *
 * Generation is meant to be run again — the draft is a first pass somebody then
 * improves, and improvement that gets overwritten is worse than not
 * regenerating at all. So every write is compared against what generation wrote
 * last time (`sites.imported`): a value still equal to that is refreshed, and a
 * value somebody has changed since is left exactly as it is and reported. That
 * is the rule the namibweb importer follows, and it is here for the same
 * reason.
 *
 * `--force` is the deliberate exception, and it refuses to touch a published
 * site.
 */
class SiteGenerator
{
    public function __construct(private readonly GenerationReport $report = new GenerationReport) {}

    public function report(): GenerationReport
    {
        return $this->report;
    }

    /**
     * Build (or refresh) a site seeded from a listing's content.
     */
    public function fromListing(Listing $listing, bool $force = false): Site
    {
        $existing = Site::where('source_listing_id', $listing->id)->first();

        if ($existing !== null) {
            $this->guardForce($existing, $force);
        }

        return DB::transaction(function () use ($existing, $listing, $force): Site {
            $site = $existing ?? $this->create(
                name: (string) $listing->name,
                type: $this->businessTypeFor($listing),
                listing: $listing,
            );

            if ($force) {
                $this->discardGeneratedContent($site);
            }

            $import = new ListingImport($this->report);
            $images = $this->importImages($site, $listing, $import, $force);

            $this->writeSiteFields($site, $this->siteFieldsFrom($listing));
            $this->writeBlocks($site, $this->payloadsFrom($listing, $import, $images));

            return $site->refresh();
        });
    }

    /**
     * Build an empty site for a business we hold nothing about — the same kit,
     * the same block order, with placeholders waiting to be filled.
     */
    public function empty(string $name, BusinessType $type): Site
    {
        return DB::transaction(function () use ($name, $type): Site {
            $site = $this->create($name, $type, null);

            $this->writeBlocks($site, []);

            return $site->refresh();
        });
    }

    private function create(string $name, BusinessType $type, ?Listing $listing): Site
    {
        $slug = $this->uniqueSlug($name);

        /** @var array<string, string> $typeAccents */
        $typeAccents = (array) config('sites.type_accents', []);

        return Site::create([
            'partner_id' => $listing?->partner_id,
            'source_listing_id' => $listing?->id,
            'business_type' => $type,
            'name' => $name,
            'slug' => $slug,
            // Null where no wildcard domain is configured, which is local
            // development and CI. A site without a host is entirely normal and
            // is reviewed at /_sites/{slug}.
            'host' => Site::defaultHostFor($slug),
            'status' => SiteStatus::Draft,
            'accent' => $typeAccents[$type->value] ?? config('sites.default_accent', 'copper'),
        ]);
    }

    /**
     * A lodge, a restaurant, a workshop — and the one listing type that splits.
     * A self-drive hire company and a guided tour operator are different
     * businesses with different websites, and `vehicle_category` is what the
     * travel platform already uses to tell them apart.
     */
    private function businessTypeFor(Listing $listing): BusinessType
    {
        if ($listing->vehicle_category === VehicleCategory::GuidedTour) {
            return BusinessType::TourOperator;
        }

        return BusinessType::fromListingType($listing->type);
    }

    /**
     * Facts about the business — an address, a telephone number, coordinates.
     * Not gated on the content ladder: a fact about a lodge is not authored
     * expression, and a directory acquires no rights in where a lodge is by
     * writing it down. See ListingImport for the full mapping.
     *
     * @return array<string, mixed>
     */
    private function siteFieldsFrom(Listing $listing): array
    {
        return array_filter([
            'contact_email' => $listing->contact_email,
            'contact_phone' => $listing->phone,
            'whatsapp' => $listing->phone,
            'address' => $listing->address,
            'latitude' => $listing->latitude,
            'longitude' => $listing->longitude,
            'social_links' => $listing->social_links,
        ], fn ($value) => filled($value));
    }

    /**
     * @return array<int, SiteImage>
     */
    private function importImages(Site $site, Listing $listing, ListingImport $import, bool $force): array
    {
        $existing = $site->images()->orderBy('sort')->get();

        // Copying is the expensive part and the one with a bill attached, so a
        // re-run does not do it again. --force has already emptied this.
        if ($existing->isNotEmpty() && ! $force) {
            return $existing->all();
        }

        $importer = new ImageImporter($this->report);

        return $importer->copyAll(
            $site,
            $import->photos($listing),
            $import->photoSource($listing),
            (string) $listing->name,
        );
    }

    /**
     * The block payloads a listing produces.
     *
     * A block missing from this map is created from its own defaults — present,
     * empty, and not rendered until somebody fills it.
     *
     * @param  array<int, SiteImage>  $images
     * @return array<string, array<string, mixed>>
     */
    private function payloadsFrom(Listing $listing, ListingImport $import, array $images): array
    {
        $hero = $images[0] ?? null;
        $gallery = array_slice($images, 1, 12);
        $short = $import->shortDescription($listing);
        $description = $import->description($listing);
        $highlights = $import->highlights($listing);
        $hours = $import->openingHours($listing);
        $amenities = $import->amenities($listing);

        $payloads = [
            'hero' => [
                'image_id' => $hero?->id,
                'eyebrow' => $listing->city?->name,
                'headline' => (string) $listing->name,
                'subline' => $short,
                'cta_label' => null,
                'cta_href' => null,
            ],
            'about' => [
                'eyebrow' => null,
                'heading' => 'About us',
                'body' => $description,
                'image_id' => $images[1]->id ?? null,
            ],
            'gallery' => [
                'heading' => null,
                'image_ids' => array_map(fn (SiteImage $image) => $image->id, $gallery),
            ],
            'opening_hours' => [
                'heading' => 'Opening hours',
                'note' => null,
                'days' => $hours,
            ],
        ];

        // Highlights first, then what the property has, so a listing with
        // neither leaves the block empty rather than half-populated with the
        // wrong thing.
        $items = $highlights !== []
            ? array_map(fn (string $text) => ['title' => Str::limit($text, 80, ''), 'text' => null], $highlights)
            : array_map(fn (string $text) => ['title' => Str::limit($text, 80, ''), 'text' => null], array_slice($amenities, 0, 6));

        if ($items !== []) {
            $payloads['highlights'] = ['heading' => 'What we offer', 'items' => array_values($items)];
        }

        return $payloads;
    }

    /**
     * Write site columns, keeping anything edited since the last generation.
     *
     * @param  array<string, mixed>  $fields
     */
    private function writeSiteFields(Site $site, array $fields): void
    {
        $imported = $site->imported ?? [];
        $previous = is_array($imported['fields'] ?? null) ? $imported['fields'] : [];
        $written = [];

        foreach ($fields as $field => $value) {
            $current = $site->getAttribute($field);
            $wasGenerated = ($previous[$field] ?? null) === $this->comparable($current);

            if (filled($current) && ! $wasGenerated) {
                $this->report->keptEdit($field, 'edited since the last generation');
                $written[$field] = $previous[$field] ?? null;

                continue;
            }

            $site->setAttribute($field, $value);
            $written[$field] = $this->comparable($site->getAttribute($field));
            $this->report->wrote($field);
        }

        $imported['fields'] = $written + $previous;
        $site->imported = $imported;
        $site->save();
    }

    /**
     * Create the page and its blocks in the order this kind of business starts
     * in, keeping any payload edited since the last generation.
     *
     * @param  array<string, array<string, mixed>>  $payloads
     */
    private function writeBlocks(Site $site, array $payloads): void
    {
        /** @var SitePage $page */
        $page = $site->pages()->firstOrCreate(
            ['locale' => $site->default_locale, 'slug' => ''],
            ['is_home' => true, 'title' => $site->name, 'sort' => 0],
        );

        $imported = $site->imported ?? [];
        $previous = is_array($imported['blocks'] ?? null) ? $imported['blocks'] : [];
        $written = [];

        foreach (BlockRegistry::layoutFor($site->business_type) as $sort => $type) {
            $definition = BlockRegistry::find($type);

            if ($definition === null) {
                continue;
            }

            $payload = ($payloads[$type] ?? []) + $definition->defaults();
            $block = $page->blocks()->firstOrNew(['type' => $type]);

            // Loose comparison on purpose: these payloads have made a round
            // trip through JSON, and two arrays that differ only in key order
            // are the same payload. A strict comparison would report every
            // block as edited and then never refresh anything.
            if ($block->exists && $block->data != ($previous[$type] ?? null) && $block->data !== []) {
                $this->report->keptEdit($type, 'this block was edited since the last generation');
                $written[$type] = $previous[$type] ?? null;

                continue;
            }

            $block->fill(['data' => $payload, 'sort' => $sort, 'is_enabled' => true])->save();
            $written[$type] = $block->data;

            if (! $definition->isFilled($block->data)) {
                $this->report->disabled($type);
            }
        }

        $imported['blocks'] = $written + $previous;
        $site->imported = $imported;
        $site->save();
    }

    /**
     * A published site is somebody's shopfront. Rebuilding it from a listing
     * would throw away whatever has been written on it since, in public, with
     * no undo — so it is refused rather than confirmed, and the operator moves
     * it back to draft if they really mean it.
     */
    private function guardForce(Site $site, bool $force): void
    {
        if ($force && $site->isPublished()) {
            throw new RuntimeException(
                "[{$site->name}] is published. Rebuilding would discard whatever has been edited on the live site; "
                .'move it back to draft first if that is really what you want.'
            );
        }
    }

    /**
     * Empty a draft of its generated content so it can be rebuilt.
     *
     * Only ever objects under this site's own prefix, and only rows this site
     * owns — nothing here can reach a listing's photographs, which is the
     * whole reason the bytes were copied in the first place.
     */
    private function discardGeneratedContent(Site $site): void
    {
        foreach ($site->images as $image) {
            try {
                Storage::disk('r2')->delete($image->key);
            } catch (Throwable) {
                // A file already gone is the state we wanted. The row goes
                // either way; an orphaned object costs storage, a dangling row
                // costs a broken page.
            }

            $image->delete();
        }

        SiteBlock::whereIn('site_page_id', $site->pages()->select('id'))->delete();

        $site->imported = null;
        $site->save();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'site';
        $slug = $base;
        $n = 2;

        while (Site::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /** JSON round-trips decimals and enums to strings; compare like for like. */
    private function comparable(mixed $value): mixed
    {
        return is_scalar($value) || $value === null || is_array($value) ? $value : (string) $value;
    }
}
