<?php

namespace App\Sites\Rendering;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a tour request can be for: the business's own published listings that
 * take requests and have a fixed length — a guided tour, an excursion, a day
 * trip. Read live from the partner, so a tour added or withdrawn on the
 * platform is in or out of the form without anybody editing the website.
 *
 * Restaurants are excluded: a table has no length, and it has a form of its own.
 */
final class EnquiryTours
{
    /**
     * @return Collection<int, Listing>
     */
    public static function for(Site $site): Collection
    {
        if ($site->partner_id === null) {
            return collect();
        }

        return Listing::query()
            ->where('partner_id', $site->partner_id)
            ->where('is_published', true)
            ->where('accepts_inquiries', true)
            ->where('type', '!=', ListingType::Restaurant)
            ->where('duration_minutes', '>', 0)
            ->orderBy('duration_minutes')
            ->orderBy('slug')
            ->get();
    }

    /** The listing among this site's tours, or null when it is not one of them. */
    public static function find(Site $site, int $listingId): ?Listing
    {
        return self::for($site)->firstWhere('id', $listingId);
    }

    /** Calendar days a listing runs: 11,520 minutes is 8 days, a 3-hour excursion is 1. */
    public static function days(Listing $listing): int
    {
        return max(1, (int) ceil(((int) $listing->duration_minutes) / 1440));
    }

    /**
     * The day the tour ends, counting the start as day one — "8 days / 7
     * nights" starting on the 12th ends on the 19th. Null for anything that
     * ends the day it starts.
     */
    public static function endDate(Listing $listing, CarbonImmutable $start): ?CarbonImmutable
    {
        $days = self::days($listing);

        return $days > 1 ? $start->addDays($days - 1) : null;
    }
}
