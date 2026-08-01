<?php

namespace App\Http\Controllers;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\Region;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * How many listings to feature per category on the homepage. A magazine
     * front page curates a handful of picks per section rather than dumping
     * the whole catalog — the full catalog stays one click away via search.
     */
    private const PER_CATEGORY_LIMIT = 10;

    public function __invoke(): Response
    {
        // Rotate the selection daily so the homepage doesn't always show the
        // same picks, while still always preferring listings that have a
        // real photo (image or gallery). Re-seeding once a day (rather than
        // per request) means the picks stay stable across reloads within
        // the same day.
        $daySeed = now()->format('Y-m-d');

        $listings = collect(ListingType::cases())
            ->flatMap(fn (ListingType $type) => Listing::query()
                ->where('is_published', true)
                ->where('type', $type)
                ->orderByRaw("(image IS NOT NULL OR json_array_length(COALESCE(gallery, '[]')) > 0) DESC")
                ->orderByDesc('is_featured')
                ->orderByRaw('MD5(id::text || ?)', [$daySeed])
                ->limit(self::PER_CATEGORY_LIMIT)
                ->get([
                    'id', 'type', 'name', 'slug', 'description',
                    'image', 'region', 'address', 'latitude', 'longitude',
                    'price_from', 'price_currency', 'rating', 'rating_count',
                ]))
            ->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'type' => $listing->type->value,
                'name' => $listing->name,
                'slug' => $listing->slug,
                'description' => $listing->description,
                'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
                'region' => self::detectTown($listing->address) ?? $listing->region,
                'latitude' => $listing->latitude !== null ? (float) $listing->latitude : null,
                'longitude' => $listing->longitude !== null ? (float) $listing->longitude : null,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
                'rating' => $listing->rating !== null ? (float) $listing->rating : null,
                'rating_count' => $listing->rating_count,
            ]);

        $regions = Region::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'blurb', 'image', 'listing_region'])
            ->map(fn (Region $region) => [
                'name' => $region->name,
                'slug' => $region->slug,
                'blurb' => $region->blurb,
                'image' => $region->image ? self::resolveMediaUrl($region->image) : null,
                'listing_region' => $region->listing_region,
            ]);

        return Inertia::render('Welcome', [
            'listings' => $listings,
            'regions' => $regions,
        ]);
    }

    /**
     * Namibian addresses conventionally end with the town name (e.g. "Kramersdorf,
     * Swakopmund"), but scraped listings don't always have a comma before it — so
     * match against known towns instead of blindly taking the last address segment.
     */
    private static function detectTown(?string $address): ?string
    {
        if (! $address) {
            return null;
        }

        $towns = [
            'Windhoek', 'Swakopmund', 'Walvis Bay', 'Otjiwarongo', 'Oshakati',
            'Rundu', 'Tsumeb', 'Henties Bay', 'Rehoboth', 'Gobabis', 'Sesriem',
            'Okahandja', 'Keetmanshoop', 'Lüderitz', 'Katima Mulilo', 'Ondangwa',
            'Outjo', 'Mariental', 'Karibib', 'Omaruru', 'Grootfontein', 'Opuwo',
            'Otavi', 'Usakos', 'Aranos', 'Khorixas', 'Oshikango', 'Oranjemund',
        ];

        $match = null;
        $lastPosition = -1;

        foreach ($towns as $town) {
            $position = mb_strripos($address, $town);

            if ($position !== false && $position > $lastPosition) {
                $match = $town;
                $lastPosition = $position;
            }
        }

        return $match;
    }
}
