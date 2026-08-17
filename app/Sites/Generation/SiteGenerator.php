<?php

namespace App\Sites\Generation;

use App\Enums\BusinessType;
use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Enums\SiteStatus;
use App\Enums\VehicleCategory;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use App\Sites\HeroLines;
use App\Sites\LegalText;
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
     * Build (or refresh) a site seeded from a partner's own data.
     *
     * Used when the partner has no listing on the travel platform — a shop, a
     * boutique, a workshop. The same block kit as a listing-sourced site, with
     * the partner's name, contact details and bio as the starting point. Images
     * are left for the customer to upload; there is nothing here we could copy.
     */
    public function fromPartner(Partner $partner, BusinessType $type, bool $force = false): Site
    {
        $existing = Site::where('partner_id', $partner->id)
            ->whereNull('source_listing_id')
            ->first();

        if ($existing !== null) {
            $this->guardForce($existing, $force);
        }

        return DB::transaction(function () use ($existing, $partner, $type, $force): Site {
            $site = $existing ?? $this->create(
                name: $partner->name,
                type: $type,
                listing: null,
                partner: $partner,
            );

            if ($force) {
                $this->discardGeneratedContent($site);
            }

            $images = $this->importPartnerImages($site, $partner, $force);

            $this->writeSiteFields($site, $this->siteFieldsFromPartner($partner), $force);
            $this->writeSiteFields($site, $this->legalFieldsFrom($site), $force);
            $this->writeBlocks($site, $this->payloadsFromPartner($site, $partner, $images));

            return $site->refresh();
        });
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

            $this->writeSiteFields($site, $this->siteFieldsFrom($listing), $force);
            // After the fields above, not with them: the legal text quotes the
            // address and the contact email, so it has to be written from a
            // site that already has them.
            $this->writeSiteFields($site, $this->legalFieldsFrom($site), $force);
            $this->writeBlocks($site, $this->payloadsFrom($site, $listing, $import, $images));

            return $site->refresh();
        });
    }

    /**
     * Build an empty site for a business we hold nothing about — the same kit,
     * the same block order, with placeholders waiting to be filled.
     */
    public function empty(string $name, BusinessType $type, Partner $partner): Site
    {
        return DB::transaction(function () use ($name, $type, $partner): Site {
            $site = $this->create($name, $type, null, $partner);

            $this->writeSiteFields($site, $this->legalFieldsFrom($site));
            $this->writeBlocks($site, []);

            return $site->refresh();
        });
    }

    private function create(string $name, BusinessType $type, ?Listing $listing, ?Partner $partner = null): Site
    {
        $slug = $this->uniqueSlug($name);

        /** @var array<string, string> $typeAccents */
        $typeAccents = (array) config('sites.type_accents', []);

        return Site::create([
            'partner_id' => $listing !== null ? $listing->partner_id : $partner?->id,
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
    /**
     * Which of the two a restaurant's form starts as.
     *
     * A table where it takes tables, ordering where it only takes orders, and a
     * plain contact form where it takes neither online — walk-ins are a real
     * way to run a restaurant, and a form promising a booking nobody accepts is
     * worse than no form. `EnquiryBlock::formTypeFor()` applies the same rule at
     * render time, for the sites generated before the switches existed and for
     * a business that changes its mind afterwards.
     */
    private function restaurantFormType(Listing $listing): EnquiryFormType
    {
        return match (true) {
            (bool) $listing->accepts_table_reservations => EnquiryFormType::TableReservation,
            (bool) $listing->accepts_orders => EnquiryFormType::RestaurantOrder,
            default => EnquiryFormType::Contact,
        };
    }

    private function businessTypeFor(Listing $listing): BusinessType
    {
        if ($listing->vehicle_category === VehicleCategory::GuidedTour) {
            return BusinessType::TourOperator;
        }

        return BusinessType::fromListingType($listing->type);
    }

    /**
     * @return array<string, mixed>
     */
    private function siteFieldsFromPartner(Partner $partner): array
    {
        $social = array_filter([
            'instagram' => $partner->instagram,
            'facebook' => $partner->facebook,
        ], fn ($v) => filled($v));

        return array_filter([
            'contact_email' => $partner->email,
            'contact_phone' => $partner->phone,
            'whatsapp' => $partner->phone,
            'address' => $partner->address,
            'latitude' => $partner->latitude,
            'longitude' => $partner->longitude,
            'social_links' => $social ?: null,
        ], fn ($v) => filled($v));
    }

    /**
     * @param  array<int, SiteImage>  $images
     * @return array<string, array<string, mixed>>
     */
    private function payloadsFromPartner(Site $site, Partner $partner, array $images): array
    {
        $bio = $partner->getTranslation('bio', 'en', false) ?: null;
        $short = filled($partner->short_description) ? $partner->short_description : null;
        $hero = $images[0] ?? null;
        $gallery = array_slice($images, 1, 12);

        return [
            'hero' => [
                'image_id' => $hero?->id,
                'eyebrow' => null,
                'headline' => HeroLines::for($site->business_type, $site->slug),
                'subline' => $this->fit($short ?? $bio, 240, 'hero subline'),
                'cta_label' => null,
                'cta_href' => null,
            ],
            'about' => [
                'eyebrow' => null,
                'heading' => 'About us',
                'body' => $this->fit($bio, 8000, 'about text'),
                'image_id' => $images[1]->id ?? null,
            ],
            'gallery' => [
                'heading' => null,
                'image_ids' => array_map(fn (SiteImage $image) => $image->id, $gallery),
            ],
            'enquiry' => [
                'heading' => EnquiryFormType::Contact->heading(),
                'intro' => null,
                // A partner-only site has no listing behind it, so there is
                // nothing to reserve and nothing on a menu. The owner picks a
                // product order once the shop has priced products.
                'form_type' => EnquiryFormType::Contact->value,
                'channel' => EnquiryBlock::CHANNEL_EMAIL,
                'button_label' => null,
            ],
        ];
    }

    /**
     * @return array<int, SiteImage>
     */
    private function importPartnerImages(Site $site, Partner $partner, bool $force): array
    {
        $existing = $site->images()->orderBy('sort')->get();

        if ($existing->isNotEmpty() && ! $force) {
            return $existing->all();
        }

        $keys = array_filter([
            $partner->image,
            ...((array) ($partner->gallery ?? [])),
        ], fn ($v) => filled($v));

        if ($keys === []) {
            return [];
        }

        $importer = new ImageImporter($this->report);

        return $importer->copyAll($site, $keys, ContentSource::Partner, $partner->name);
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
     * The first version of the three texts the business owns and then edits.
     *
     * Written like any other generated field, which means a rebuild keeps a
     * version the business has changed. See App\Sites\LegalText for why the
     * system writes these at all when it writes no other legal wording.
     *
     * @return array<string, mixed>
     */
    private function legalFieldsFrom(Site $site): array
    {
        return [
            'legal_copyright' => $site->name,
            'legal_privacy' => LegalText::defaultPrivacy($site),
            'legal_imprint' => LegalText::defaultImprint($site),
        ];
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
    private function payloadsFrom(Site $site, Listing $listing, ListingImport $import, array $images): array
    {
        $hero = $images[0] ?? null;
        $gallery = array_slice($images, 1, 12);
        $short = $import->shortDescription($listing);
        $description = $import->description($listing);
        $highlights = $import->highlights($listing);
        $hours = $import->openingHours($listing);
        $amenities = $import->amenities($listing);

        // What the one contact form starts out as. A restaurant is asked for
        // whichever of the two it says it takes online — the same two switches
        // the listing page reads, rather than a guess from the business type
        // that then contradicts them.
        $enquiryType = $listing->type === ListingType::Restaurant
            ? $this->restaurantFormType($listing)
            : EnquiryFormType::StayRequest;

        $payloads = [
            'hero' => [
                'image_id' => $hero?->id,
                'eyebrow' => $this->fit($listing->city?->name, 60, 'hero eyebrow'),
                // Not the name: the bar already carries it, and setting it
                // twice on the first screen reads as a fault rather than as
                // emphasis. See App\Sites\HeroLines — and it is the first thing
                // the Website tab lets anybody change.
                'headline' => HeroLines::for($this->businessTypeFor($listing), $site->slug),
                'subline' => $this->fit($short, 240, 'hero subline'),
                'cta_label' => null,
                'cta_href' => null,
            ],
            'about' => [
                'eyebrow' => null,
                'heading' => 'About us',
                'body' => $this->fit($description, 8000, 'about text'),
                'image_id' => $images[1]->id ?? null,
            ],
            'gallery' => [
                'heading' => null,
                'image_ids' => array_map(fn (SiteImage $image) => $image->id, $gallery),
            ],
            'enquiry' => [
                'heading' => $enquiryType->heading(),
                'intro' => null,
                // A lodge is asked for dates, a restaurant for a table,
                // everything else for a stay-shaped request. The owner can
                // change it — that is why it is a field and not a derivation.
                'form_type' => $enquiryType->value,
                'channel' => EnquiryBlock::CHANNEL_EMAIL,
                'button_label' => null,
            ],
            'opening_hours' => [
                'heading' => 'Opening hours',
                'note' => null,
                'days' => array_map(fn (array $row): array => [
                    'day' => (string) $this->fit($row['day'], 40, 'opening hours'),
                    'hours' => (string) $this->fit($row['hours'], 60, 'opening hours'),
                ], $hours),
            ],
        ];

        // Highlights first, then what the property has, so a listing with
        // neither leaves the block empty rather than half-populated with the
        // wrong thing.
        $source = $highlights !== [] ? $highlights : array_slice($amenities, 0, 6);
        $items = array_map(
            fn (string $text): array => ['title' => $this->fit($text, 80, 'highlight'), 'text' => null],
            $source,
        );

        if ($items !== []) {
            $payloads['highlights'] = ['heading' => 'What we offer', 'items' => array_values($items)];
        }

        return $payloads;
    }

    /**
     * Cut a value down to what the block will accept, at a word boundary.
     *
     * A block's limits are design decisions — a hero subline is one sentence,
     * not three paragraphs — and a listing's own fields are written under no
     * such constraint. Where they collide, the text gets shortened and the
     * report says so.
     *
     * It must not be an exception. Generation is a one-click promise, and a
     * lodge whose `short_description` happens to run to 300 characters is not
     * an error case: it is Tuesday. Before this, that listing produced no
     * website at all and a validation message nobody outside the code could
     * act on. The strict validation on the block itself stays exactly as it
     * is — this makes generation produce payloads that satisfy it, rather than
     * loosening what the renderer is allowed to be handed.
     */
    private function fit(?string $value, int $max, string $field): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $this->report->shortened($field, $max);

        // Cut at the last word boundary, unless that would throw away most of
        // the allowance — a long string with no spaces in it (a URL, a run-on
        // name) then gets a hard cut rather than becoming empty, which is what
        // a naive "truncate at the last space" does to it.
        $cut = mb_substr($value, 0, $max);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:–-") ?: mb_substr($value, 0, $max);
    }

    /**
     * Write site columns, keeping anything edited since the last generation.
     *
     * Under `--force` nothing is kept. That is what the flag means everywhere
     * else — blocks and images are already gone by this point — and a rebuild
     * that quietly preserved half the site while reporting those fields as
     * "edited since" would be describing something that never happened: force
     * has just thrown away the record of having written them.
     *
     * @param  array<string, mixed>  $fields
     */
    private function writeSiteFields(Site $site, array $fields, bool $force = false): void
    {
        $imported = $site->imported ?? [];
        $previous = is_array($imported['fields'] ?? null) ? $imported['fields'] : [];
        $written = [];

        foreach ($fields as $field => $value) {
            $current = $site->getAttribute($field);
            $wasGenerated = ($previous[$field] ?? null) === $this->comparable($current);

            if (! $force && filled($current) && ! $wasGenerated) {
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
