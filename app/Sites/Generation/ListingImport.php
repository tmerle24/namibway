<?php

namespace App\Sites\Generation;

use App\Enums\ContentSource;
use App\Models\Listing;

/**
 * What may be taken from a listing, field by field.
 *
 * ## The hard rule, and where it can actually be applied
 *
 * Content whose source is `directory` is not publishable — someone else's prose
 * and someone else's photography, and editing them does not make them ours.
 * That rule is absolute and it is enforced here.
 *
 * The brief asks for a check "on every single field", and the code cannot quite
 * do that, so this is what it does instead and why. A listing carries exactly
 * two provenance columns: `description_source`, and `photos_source` covering
 * `image` and `gallery` together. Everything else — address, telephone, email,
 * coordinates, opening hours — has none.
 *
 * So the mapping is:
 *
 *  - **Authored text** (description, short description, highlights) rides on
 *    `description_source`. Unpublishable in, nothing out.
 *  - **Photographs** ride on `photos_source`, as one set, which is the
 *    granularity the column has. Google Places imagery is imported for
 *    prospecting only (see ImageImporter). `pending_*` is never imported at
 *    all: staged photographs are by definition not approved yet.
 *  - **Facts** — an address, a telephone number, a set of coordinates, the days
 *    a shop is open — are imported regardless, because a fact about a business
 *    is not authored expression and a directory does not acquire rights in the
 *    location of a lodge by writing it down.
 *  - **`facilities`**, the free-text list, is the one borderline case: it is
 *    closer to a directory's words than to a fact, so it is gated on
 *    `description_source` — the best signal that listing has. The curated
 *    `amenities` are not gated: those were chosen by somebody who owns the
 *    property.
 *
 * A null source is treated as importable and reported as unrecorded. It means
 * the value predates provenance tracking, which in this schema means somebody
 * typed it — the same reading ContentSource::outranks() takes.
 */
class ListingImport
{
    public function __construct(private readonly GenerationReport $report) {}

    /** Whether the listing's authored text may be published on a customer site. */
    public function mayUseText(Listing $listing): bool
    {
        $source = $listing->description_source;

        if ($source === null) {
            return true;
        }

        if (! $source->publishable()) {
            $this->report->skip('text', 'the only text we hold came from a directory and is not ours to publish');

            return false;
        }

        return true;
    }

    /** Whether the listing's live photographs may be copied to a customer site. */
    public function mayUsePhotos(Listing $listing): bool
    {
        $source = $this->photoSource($listing);

        if (! $source->publishable()) {
            $this->report->skip('photos', 'the photographs on this listing came from a directory');

            return false;
        }

        return true;
    }

    public function photoSource(Listing $listing): ContentSource
    {
        // The same default the listing model itself falls back to when a row
        // predates the column (Listing::hasApprovablePhotos).
        return $listing->photos_source ?? ContentSource::WebsiteScrape;
    }

    /**
     * The live photographs, in order, hero first.
     *
     * `pending_image`/`pending_gallery` are deliberately absent. Those are
     * staged for an approval that has not happened, and a website is a
     * considerably more public place to publish an unapproved photograph than
     * the review queue it is sitting in.
     *
     * @return array<int, string>
     */
    public function photos(Listing $listing): array
    {
        if (! $this->mayUsePhotos($listing)) {
            return [];
        }

        $photos = array_filter(array_merge(
            [$listing->image],
            is_array($listing->gallery) ? $listing->gallery : [],
        ), fn ($value) => is_string($value) && trim($value) !== '');

        return array_values($photos);
    }

    /**
     * Authored prose, or null.
     */
    public function description(Listing $listing): ?string
    {
        if (! $this->mayUseText($listing)) {
            return null;
        }

        return filled($listing->description) ? (string) $listing->description : null;
    }

    public function shortDescription(Listing $listing): ?string
    {
        if (! $this->mayUseText($listing)) {
            return null;
        }

        return filled($listing->short_description) ? (string) $listing->short_description : null;
    }

    /**
     * @return array<int, string>
     */
    public function highlights(Listing $listing): array
    {
        if (! $this->mayUseText($listing)) {
            return [];
        }

        // Read through getAttribute() rather than as a property, deliberately.
        // `highlights` is a json column that is translatable but carries no
        // array cast, so static analysis reads it as a string while at runtime
        // it is a list of short phrases. Going through the accessor keeps the
        // value honestly `mixed` and the checks below meaningful, instead of
        // being narrowed away against a type the column does not really have.
        $highlights = $listing->getAttribute('highlights');

        if (! is_array($highlights)) {
            return [];
        }

        return array_values(array_filter(
            $highlights,
            fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
    }

    /**
     * What the property has.
     *
     * Curated amenities are somebody's own answer about their own property and
     * are taken whatever the text source says. The free-text list is a
     * directory's words often enough that it rides with the prose.
     *
     * @return array<int, string>
     */
    public function amenities(Listing $listing): array
    {
        if ($listing->hasChosenAmenities()) {
            return $listing->amenityList();
        }

        if (! $this->mayUseText($listing)) {
            return [];
        }

        return $listing->amenityList();
    }

    /**
     * Opening hours as the block stores them.
     *
     * The listing column is a day => hours object written only by the AI
     * extractor reading an owner's own website, so in practice this is empty
     * for nearly every listing and the block starts switched off. That is the
     * honest outcome: a table of opening hours nobody confirmed is worse than
     * no table.
     *
     * @return array<int, array{day: string, hours: string}>
     */
    public function openingHours(Listing $listing): array
    {
        $hours = is_array($listing->opening_hours) ? $listing->opening_hours : [];
        $rows = [];

        foreach ($hours as $day => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $rows[] = ['day' => ucfirst((string) $day), 'hours' => $value];
        }

        return $rows;
    }
}
