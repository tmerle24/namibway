<?php

use App\Models\Region;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Adds the well-known Namibian "classics" travelers actually search for as
 * their own Explore cards, the same way Etosha already sits alongside Kunene
 * as a recognizable sub-destination rather than the raw political region
 * name. `image` is left null — the Explore card falls back to a generic
 * placeholder graphic until a real, rights-cleared photo is uploaded per
 * destination via Filament → Regions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $destinations = [
            ['name' => 'Windhoek', 'listing_region' => 'Khomas', 'lat' => -22.5609, 'lng' => 17.0658, 'blurb' => "Namibia's capital — cafés, markets, and where every road trip begins and ends.", 'sort' => 15],
            ['name' => 'Spitzkoppe', 'listing_region' => 'Erongo', 'lat' => -21.8333, 'lng' => 15.1833, 'blurb' => "The 'Matterhorn of Namibia' — granite peaks, San rock art, and unforgettable sunsets.", 'sort' => 25],
            ['name' => 'Skeleton Coast', 'listing_region' => 'Erongo', 'lat' => -20.5000, 'lng' => 13.4000, 'blurb' => "Fog-shrouded shipwrecks and seal colonies along one of the world's most remote coastlines.", 'sort' => 26],
            ['name' => 'Swakopmund', 'listing_region' => 'Erongo', 'lat' => -22.6784, 'lng' => 14.5257, 'blurb' => 'Coastal charm, adventure sports, and the desert meeting the Atlantic.', 'sort' => 27],
            ['name' => 'Sandwich Harbour', 'listing_region' => 'Erongo', 'lat' => -23.3500, 'lng' => 14.4833, 'blurb' => "Towering dunes plunge straight into the ocean — one of Namibia's most dramatic landscapes.", 'sort' => 28],
            ['name' => 'Sossusvlei', 'listing_region' => 'Hardap', 'lat' => -24.7333, 'lng' => 15.3000, 'blurb' => "Namibia's iconic red dunes and the ghostly clay pans of Deadvlei.", 'sort' => 35],
            ['name' => 'Naukluft', 'listing_region' => 'Hardap', 'lat' => -24.1500, 'lng' => 16.1500, 'blurb' => 'Rugged mountains and hiking trails on the edge of the Namib.', 'sort' => 36],
            ['name' => 'Fish River Canyon', 'listing_region' => 'Karas', 'lat' => -27.5847, 'lng' => 17.6187, 'blurb' => 'The second-largest canyon on Earth — multi-day hikes and epic sunset viewpoints.', 'sort' => 72],
            ['name' => 'Lüderitz', 'listing_region' => 'Karas', 'lat' => -26.6481, 'lng' => 15.1594, 'blurb' => 'A colonial-era port town on the edge of the Namib, gateway to the Kolmanskop ghost town.', 'sort' => 75],
        ];

        foreach ($destinations as $destination) {
            Region::updateOrCreate(
                ['slug' => Str::slug($destination['name'])],
                [
                    'name' => ['en' => $destination['name']],
                    'blurb' => ['en' => $destination['blurb']],
                    'listing_region' => $destination['listing_region'],
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                    'sort_order' => $destination['sort'],
                    'is_published' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        Region::whereIn('slug', [
            'windhoek', 'spitzkoppe', 'skeleton-coast', 'swakopmund',
            'sandwich-harbour', 'sossusvlei', 'naukluft', 'fish-river-canyon',
            'luderitz',
        ])->delete();
    }
};
