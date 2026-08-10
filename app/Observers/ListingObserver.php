<?php

namespace App\Observers;

use App\Models\Listing;

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
