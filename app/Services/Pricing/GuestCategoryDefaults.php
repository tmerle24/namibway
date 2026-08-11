<?php

namespace App\Services\Pricing;

use App\Enums\GuestKind;
use App\Models\GuestCategory;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Collection;

/**
 * The usual set of guest categories, created on request.
 *
 * "Profiles, not configuration" — a lodge switching to per-person pricing
 * should press one button and get Adult / Child / Infant with the bands the
 * market publishes, then adjust. Making them fill in a table first is how a
 * setup gets abandoned halfway.
 *
 * Nothing here runs on its own. A property that never prices by guests never
 * gets these rows, and a property that already has categories is left exactly
 * as it is — this creates what is missing and touches nothing that exists,
 * because a lodge's own age band is not ours to correct.
 */
final class GuestCategoryDefaults
{
    /**
     * @return Collection<int, GuestCategory>
     */
    public static function ensureFor(Listing $listing): Collection
    {
        $existing = [];

        foreach (GuestCategory::query()->where('listing_id', $listing->id)->get() as $category) {
            $existing[] = strtoupper($category->code);
        }

        $sort = 0;

        /** @var array<int, array<string, mixed>> $defaults */
        $defaults = config('booking.guest_categories', []);

        foreach ($defaults as $default) {
            $code = strtoupper((string) $default['code']);
            $sort += 10;

            if (in_array($code, $existing, true)) {
                continue;
            }

            GuestCategory::create([
                'listing_id' => $listing->id,
                'code' => $code,
                'name' => (string) $default['name'],
                'kind' => GuestKind::from((string) $default['kind']),
                'min_age' => $default['min_age'],
                'max_age' => $default['max_age'],
                'charge_percent' => (float) $default['charge_percent'],
                'counts_toward_occupancy' => (bool) $default['counts_toward_occupancy'],
                'sort' => $sort,
                'is_active' => true,
            ]);
        }

        return GuestCategory::forListing($listing);
    }
}
