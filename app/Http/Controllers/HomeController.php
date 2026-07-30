<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Region;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $listings = Listing::query()
            ->where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderBy('slug')
            ->get([
                'id', 'type', 'name', 'slug', 'description',
                'image', 'region', 'price_from', 'price_currency',
            ])
            ->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'type' => $listing->type->value,
                'name' => $listing->name,
                'slug' => $listing->slug,
                'description' => $listing->description,
                'image' => $listing->image ? self::resolveMediaUrl($listing->image) : null,
                'region' => $listing->region,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
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
}
