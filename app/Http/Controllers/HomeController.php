<?php

namespace App\Http\Controllers;

use App\Enums\ListingType;
use App\Models\Destination;
use App\Models\Listing;
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

    private const LISTING_COLUMNS = [
        'id', 'type', 'name', 'slug', 'description',
        'image', 'city_id', 'address', 'latitude', 'longitude',
        'price_from', 'price_currency', 'rating', 'rating_count',
    ];

    /**
     * Types eligible for the homepage "cover story" pick. Restricted to
     * categories that reliably have attractive photos — e.g. vehicle rental
     * listings tend to have flat product/lot shots, not the kind of hero
     * image a magazine front page wants. Revisit once the section is
     * curated manually with vetted photos rather than picked automatically.
     */
    private const FEATURED_TYPES = [
        ListingType::Accommodation,
        ListingType::Activity,
        ListingType::Restaurant,
    ];

    public function __invoke(): Response
    {
        // Rotate the selection daily so the homepage doesn't always show the
        // same picks, while still always preferring listings that have a
        // real photo (image or gallery). Re-seeding once a day (rather than
        // per request) means the picks stay stable across reloads within
        // the same day.
        $daySeed = now()->format('Y-m-d');

        $featuredPick = self::pickFeatured($daySeed);

        $listings = collect(ListingType::cases())
            ->flatMap(fn (ListingType $type) => Listing::query()
                ->where('is_published', true)
                ->where('type', $type)
                ->when($featuredPick, fn ($query) => $query->whereKeyNot($featuredPick->id))
                ->orderByRaw("(image IS NOT NULL OR json_array_length(COALESCE(gallery, '[]')) > 0) DESC")
                ->orderByDesc('is_featured')
                ->orderByRaw('MD5(id::text || ?)', [$daySeed])
                ->limit(self::PER_CATEGORY_LIMIT)
                ->with('city.region')
                ->get(self::LISTING_COLUMNS))
            ->map(fn (Listing $listing) => self::presentListing($listing));

        $destinations = Destination::query()
            ->where('is_published', true)
            ->orderByRaw('MD5(id::text || ?)', [$daySeed])
            ->with('region:id,name')
            ->get(['id', 'name', 'slug', 'blurb', 'image', 'region_id'])
            ->map(fn (Destination $destination) => [
                'name' => $destination->name,
                'slug' => $destination->slug,
                'blurb' => $destination->blurb,
                'image' => $destination->image ? self::resolveMediaUrl($destination->image) : null,
                'region_name' => $destination->region->name,
            ]);

        return Inertia::render('Welcome', [
            'listings' => $listings,
            'destinations' => $destinations,
            'featuredPick' => $featuredPick ? self::presentListing($featuredPick) : null,
        ]);
    }

    /**
     * The homepage "cover story" card. Prefers listings an admin has manually
     * curated via Filament (rotating daily if there's more than one) so a real
     * editor can drive what leads the magazine; falls back to the best
     * automatic pick — highest-rated listing with a real photo — when nobody
     * has curated anything yet.
     */
    private static function pickFeatured(string $daySeed): ?Listing
    {
        return Listing::query()
            ->where('is_published', true)
            ->where('is_homepage_pick', true)
            ->whereIn('type', self::FEATURED_TYPES)
            ->orderByRaw('MD5(id::text || ?)', [$daySeed])
            ->with('city.region')
            ->first(self::LISTING_COLUMNS)
            ?? Listing::query()
                ->where('is_published', true)
                ->whereIn('type', self::FEATURED_TYPES)
                ->orderByRaw("(image IS NOT NULL OR json_array_length(COALESCE(gallery, '[]')) > 0) DESC")
                ->orderByDesc('rating')
                ->orderByRaw('MD5(id::text || ?)', [$daySeed])
                ->with('city.region')
                ->first(self::LISTING_COLUMNS);
    }

    /** @return array<string, mixed> */
    private static function presentListing(Listing $listing): array
    {
        return [
            'id' => $listing->id,
            'type' => $listing->type->value,
            'name' => $listing->name,
            'slug' => $listing->slug,
            'description' => $listing->description,
            'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
            'region' => $listing->region,
            'city' => $listing->city ? $listing->city->name : self::detectTown($listing->address),
            'latitude' => $listing->latitude !== null ? (float) $listing->latitude : null,
            'longitude' => $listing->longitude !== null ? (float) $listing->longitude : null,
            'price_from' => $listing->price_from,
            'price_currency' => $listing->price_currency,
            'rating' => $listing->rating !== null ? (float) $listing->rating : null,
            'rating_count' => $listing->rating_count,
        ];
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
