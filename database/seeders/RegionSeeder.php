<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RegionSeeder extends Seeder
{
    /**
     * Destinations shown as clickable cards in the Explore section's "Regions
     * to explore" row. Each maps to one of the 6 political regions used on
     * Listing::region, so clicking a card can filter the catalog correctly —
     * several destinations (e.g. Etosha) are well-known sub-areas of a wider
     * political region rather than the region itself.
     */
    public function run(): void
    {
        $regions = [
            ['name' => 'Khomas', 'listing_region' => 'Khomas', 'blurb' => 'Windhoek and the central highlands — most itineraries start here.', 'image' => 'regions/khomas.jpg', 'sort' => 10],
            ['name' => 'Erongo', 'listing_region' => 'Erongo', 'blurb' => 'Coastal dunes, Swakopmund, and the Skeleton Coast approach.', 'image' => 'regions/erongo.jpg', 'sort' => 20],
            ['name' => 'Hardap', 'listing_region' => 'Hardap', 'blurb' => 'Home to the Sossusvlei dune fields and the Namib.', 'image' => 'regions/hardap.jpg', 'sort' => 30],
            ['name' => 'Kunene', 'listing_region' => 'Kunene', 'blurb' => 'Damaraland\'s rugged desert, ancient rock art, and desert-adapted wildlife.', 'image' => 'regions/kunene.jpg', 'sort' => 40],
            ['name' => 'Etosha', 'listing_region' => 'Kunene', 'blurb' => 'Namibia\'s premier wildlife park — waterhole game viewing at its very best.', 'image' => 'regions/etosha.jpg', 'sort' => 50],
            ['name' => 'Otjozondjupa', 'listing_region' => 'Otjozondjupa', 'blurb' => 'Bushveld and the road east toward the Kalahari.', 'image' => 'regions/otjozondjupa.jpg', 'sort' => 60],
            ['name' => 'Karas', 'listing_region' => 'Karas', 'blurb' => 'The far south — Fish River Canyon and the Orange River.', 'image' => 'regions/karas.jpg', 'sort' => 70],
        ];

        foreach ($regions as $region) {
            Region::updateOrCreate(
                ['slug' => Str::slug($region['name'])],
                [
                    'name' => ['en' => $region['name']],
                    'blurb' => ['en' => $region['blurb']],
                    'image' => $region['image'],
                    'listing_region' => $region['listing_region'],
                    'sort_order' => $region['sort'],
                    'is_published' => true,
                ]
            );
        }
    }
}
