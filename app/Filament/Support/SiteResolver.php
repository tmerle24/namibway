<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;

/**
 * Resolves the Site for either a Listing or a Partner owner.
 *
 * Listing-sourced sites are keyed on source_listing_id; partner-only sites
 * (built from the partner record itself) are keyed on partner_id with no
 * source_listing_id. The distinction matters: a lodge's own listing site and
 * a standalone partner site can coexist on the same partner record, and the
 * two must not be confused.
 */
class SiteResolver
{
    public static function for(Listing|Partner $owner): ?Site
    {
        return $owner instanceof Listing
            ? Site::where('source_listing_id', $owner->id)->first()
            : Site::where('partner_id', $owner->id)->whereNull('source_listing_id')->first();
    }
}
