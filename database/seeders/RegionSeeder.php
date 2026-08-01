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
            ['name' => 'Khomas',            'listing_region' => 'Khomas',       'lat' => -22.5597, 'lng' => 17.0832, 'blurb' => 'Windhoek and the central highlands — most itineraries start here.',          'image' => 'regions/khomas.jpg',       'sort' => 10],
            ['name' => 'Windhoek',          'listing_region' => 'Khomas',       'lat' => -22.5609, 'lng' => 17.0658, 'blurb' => "Namibia's capital — cafés, markets, and where every road trip begins and ends.", 'image' => null, 'sort' => 15],
            ['name' => 'Erongo',            'listing_region' => 'Erongo',       'lat' => -22.0000, 'lng' => 14.9000, 'blurb' => 'Coastal dunes, Swakopmund, and the Skeleton Coast approach.',                 'image' => 'regions/erongo.jpg',       'sort' => 20],
            ['name' => 'Spitzkoppe',        'listing_region' => 'Erongo',       'lat' => -21.8333, 'lng' => 15.1833, 'blurb' => "The 'Matterhorn of Namibia' — granite peaks, San rock art, and unforgettable sunsets.", 'image' => null, 'sort' => 25],
            ['name' => 'Skeleton Coast',    'listing_region' => 'Erongo',       'lat' => -20.5000, 'lng' => 13.4000, 'blurb' => "Fog-shrouded shipwrecks and seal colonies along one of the world's most remote coastlines.", 'image' => null, 'sort' => 26],
            ['name' => 'Swakopmund',        'listing_region' => 'Erongo',       'lat' => -22.6784, 'lng' => 14.5257, 'blurb' => 'Coastal charm, adventure sports, and the desert meeting the Atlantic.',       'image' => null, 'sort' => 27],
            ['name' => 'Sandwich Harbour',  'listing_region' => 'Erongo',       'lat' => -23.3500, 'lng' => 14.4833, 'blurb' => "Towering dunes plunge straight into the ocean — one of Namibia's most dramatic landscapes.", 'image' => null, 'sort' => 28],
            ['name' => 'Hardap',            'listing_region' => 'Hardap',       'lat' => -24.5000, 'lng' => 16.5000, 'blurb' => 'Home to the Sossusvlei dune fields and the Namib.',                          'image' => 'regions/hardap.jpg',       'sort' => 30],
            ['name' => 'Sossusvlei',        'listing_region' => 'Hardap',       'lat' => -24.7333, 'lng' => 15.3000, 'blurb' => "Namibia's iconic red dunes and the ghostly clay pans of Deadvlei.",         'image' => null, 'sort' => 35],
            ['name' => 'Naukluft',          'listing_region' => 'Hardap',       'lat' => -24.1500, 'lng' => 16.1500, 'blurb' => 'Rugged mountains and hiking trails on the edge of the Namib.',              'image' => null, 'sort' => 36],
            ['name' => 'Kunene',            'listing_region' => 'Kunene',       'lat' => -19.5800, 'lng' => 13.9200, 'blurb' => "Damaraland's rugged desert, ancient rock art, and desert-adapted wildlife.", 'image' => 'regions/kunene.jpg',       'sort' => 40],
            ['name' => 'Etosha',            'listing_region' => 'Kunene',       'lat' => -18.8550, 'lng' => 16.3290, 'blurb' => "Namibia's premier wildlife park — waterhole game viewing at its very best.", 'image' => 'regions/etosha.jpg',       'sort' => 50],
            ['name' => 'Otjozondjupa',      'listing_region' => 'Otjozondjupa', 'lat' => -20.4600, 'lng' => 17.9200, 'blurb' => 'Bushveld and the road east toward the Kalahari.',                           'image' => 'regions/otjozondjupa.jpg', 'sort' => 60],
            ['name' => 'Karas',             'listing_region' => 'Karas',        'lat' => -27.7500, 'lng' => 18.0000, 'blurb' => 'The far south — Fish River Canyon and the Orange River.',                   'image' => 'regions/karas.jpg',        'sort' => 70],
            ['name' => 'Fish River Canyon', 'listing_region' => 'Karas',        'lat' => -27.5847, 'lng' => 17.6187, 'blurb' => 'The second-largest canyon on Earth — multi-day hikes and epic sunset viewpoints.', 'image' => null, 'sort' => 72],
            ['name' => 'Lüderitz',          'listing_region' => 'Karas',        'lat' => -26.6481, 'lng' => 15.1594, 'blurb' => 'A colonial-era port town on the edge of the Namib, gateway to the Kolmanskop ghost town.', 'image' => null, 'sort' => 75],
        ];

        foreach ($regions as $region) {
            Region::updateOrCreate(
                ['slug' => Str::slug($region['name'])],
                [
                    'name' => ['en' => $region['name']],
                    'blurb' => ['en' => $region['blurb']],
                    'image' => $region['image'],
                    'listing_region' => $region['listing_region'],
                    'lat' => $region['lat'],
                    'lng' => $region['lng'],
                    'sort_order' => $region['sort'],
                    'is_published' => true,
                ]
            );
        }
    }
}
