<?php

namespace App\Observers;

use App\Models\Listing;
use App\Models\Place;

class ListingObserver
{
    /**
     * A newly known website is the most valuable enrichment trigger we have.
     *
     * Whatever put it there — a scraper import, an admin edit, a partner
     * claiming their listing — the owner's own site is the one source whose
     * text and photos we may actually use. So a changed website marks the
     * listing as due for a crawl by clearing og_scraped_at, and the
     * namibway:scrape-websites sweep (every five minutes, oldest and
     * never-crawled first — see routes/console.php) picks it up within one
     * cycle.
     *
     * Marking rather than dispatching keeps this out of the request/import
     * path: no queue coupling, no crawl fired from inside a database
     * transaction, and one sweep that is already rate-limited per host
     * instead of a burst of jobs an import would trigger.
     */
    /**
     * A listing filed in a city inherits that city's place, so that everything
     * Kaia routes on has one without anybody having to know the split exists.
     *
     * Only ever fills a blank: a place set by hand — a camp whose postal
     * address is Outjo but which sits in Etosha — is the whole reason the two
     * columns are separate and must never be overwritten by the address.
     *
     * A published listing in a city that is not yet a place makes it one (see
     * Place::forCity). A business opening somewhere is the evidence that
     * somewhere is a place, and it is better evidence than any rule written in
     * advance — which is what left this gap when the split was migrated. A
     * draft mints nothing: an unpublished listing is not evidence of anything
     * yet, and it will pass through here again when it is published.
     */
    public function saving(Listing $listing): void
    {
        if ($listing->place_id !== null || $listing->city_id === null) {
            return;
        }

        if (! $listing->isDirty('city_id') && $listing->exists && ! $listing->isDirty('is_published')) {
            return;
        }

        $city = $listing->city;

        if ($city === null) {
            return;
        }

        if ($listing->is_published) {
            $listing->place()->associate(Place::forCity($city));

            return;
        }

        $listing->place_id = $city->place_id;
    }

    public function saved(Listing $listing): void
    {
        if (! $listing->wasChanged('website') || blank($listing->website)) {
            return;
        }

        if ($listing->og_scraped_at === null) {
            return; // already queued: never crawled, or marked due earlier
        }

        // saveQuietly, or this observer would re-enter on its own write.
        $listing->forceFill(['og_scraped_at' => null])->saveQuietly();
    }
}
