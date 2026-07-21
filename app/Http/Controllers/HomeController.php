<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $listings = Listing::query()
            ->where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->get([
                'id', 'type', 'name', 'slug', 'description',
                'region', 'price_from', 'price_currency',
            ]);

        return Inertia::render('Welcome', [
            'listings' => $listings,
        ]);
    }
}
