<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Support\Facades\Storage;
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
                'image' => $listing->image ? Storage::disk('public')->url($listing->image) : null,
                'region' => $listing->region,
                'price_from' => $listing->price_from,
                'price_currency' => $listing->price_currency,
            ]);

        return Inertia::render('Welcome', [
            'listings' => $listings,
        ]);
    }
}
