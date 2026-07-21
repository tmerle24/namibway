<?php

namespace Database\Seeders;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ListingSeeder extends Seeder
{
    /**
     * Curated, presentation-ready demo listings — matches the routes referenced
     * in the Kaia chat demo and the Explore section's photography, rather than
     * random Faker company names. Accommodation and vehicle entries carry a
     * partner (see PartnerSeeder, run first) and a few highlights for the
     * detail page — highlights also double as tags Kaia reads to match
     * camping-suitable accommodation to a camper rental (e.g. "Camping",
     * "Camper").
     */
    public function run(): void
    {
        $listings = [
            // Accommodation
            ['type' => ListingType::Accommodation, 'name' => 'Olive Grove Guesthouse', 'region' => 'Khomas', 'lat' => -22.5700, 'lng' => 17.0836, 'price' => 1450, 'featured' => false, 'description' => 'A boutique city base in Windhoek — the natural first and last night of most Namibia itineraries.', 'highlights' => ['Free WiFi', 'Airport shuttle', 'Pool', 'Free parking']],
            ['type' => ListingType::Accommodation, 'name' => 'Etosha Safari Lodge', 'region' => 'Kunene', 'lat' => -19.2000, 'lng' => 15.9333, 'price' => 2100, 'featured' => false, 'description' => 'Mid-range base for Etosha game drives, close to the Okaukuejo waterhole where elephants come to drink at dusk.', 'highlights' => ['Waterhole views', 'Game drives on-site', 'Pool', 'Restaurant & bar']],
            ['type' => ListingType::Accommodation, 'name' => 'Ongava Tented Camp', 'region' => 'Kunene', 'lat' => -19.4167, 'lng' => 15.9500, 'price' => 5200, 'featured' => true, 'description' => 'Private reserve on Etosha\'s southern boundary — tented luxury with waterhole views from every unit.', 'highlights' => ['Private waterhole', 'All-inclusive', 'Guided walks', 'Rhino tracking']],
            ['type' => ListingType::Accommodation, 'name' => 'Doro Nawas Camp', 'region' => 'Kunene', 'lat' => -20.5833, 'lng' => 14.4167, 'price' => 4800, 'featured' => true, 'description' => 'Desert-adapted elephants and 6,000-year-old rock art at Twyfelfontein, a short drive from camp.', 'highlights' => ['Desert-adapted elephants', 'Twyfelfontein nearby', 'Pool', 'All-inclusive']],
            ['type' => ListingType::Accommodation, 'name' => 'The Delight Hotel', 'region' => 'Erongo', 'lat' => -22.6783, 'lng' => 14.5267, 'price' => 1650, 'featured' => false, 'description' => 'Colourful, mid-range coastal stay in Swakopmund, walking distance from the jetty and dune activities.', 'highlights' => ['Walk to the jetty', 'Free WiFi', 'Breakfast included']],
            ['type' => ListingType::Accommodation, 'name' => 'Sossus Dune Lodge', 'region' => 'Hardap', 'lat' => -24.7333, 'lng' => 15.8000, 'price' => 3400, 'featured' => false, 'description' => 'The only lodge inside the Sossusvlei park gate — first onto the dunes before the tour buses arrive.', 'highlights' => ['Inside the park gate', 'Pool', 'Early dune access']],
            ['type' => ListingType::Accommodation, 'name' => 'Little Kulala', 'region' => 'Hardap', 'lat' => -24.6167, 'lng' => 15.7333, 'price' => 8900, 'featured' => true, 'description' => 'Dune-side luxury at the gates of Sossusvlei, with a private plunge pool facing the red sand.', 'highlights' => ['Private plunge pool', 'All-inclusive', 'Star-bed sleepout', 'Free parking']],
            ['type' => ListingType::Accommodation, 'name' => 'Canyon Roadhouse', 'region' => 'Karas', 'lat' => -27.5333, 'lng' => 17.8500, 'price' => 1950, 'featured' => false, 'description' => 'A quirky, vintage-car-themed stop above Fish River Canyon — as memorable as the view itself.', 'highlights' => ['Pool', 'Restaurant on-site', 'Canyon viewpoint drives']],
            ['type' => ListingType::Accommodation, 'name' => 'Etosha Village Campsite', 'region' => 'Kunene', 'lat' => -19.1833, 'lng' => 15.9333, 'price' => 350, 'featured' => false, 'description' => 'A simple, well-run campsite just outside Etosha\'s Andersson Gate — bring your own tent or rooftop tent and wake up to the sounds of the bush.', 'highlights' => ['Camping', 'Braai facilities', 'Ablution block', 'Near Etosha gate']],
            ['type' => ListingType::Accommodation, 'name' => 'Sesriem Oshana Campsite', 'region' => 'Hardap', 'lat' => -24.4975, 'lng' => 15.7947, 'price' => 400, 'featured' => false, 'description' => 'The only campsite inside the Sossusvlei park gate — the same early dune access as the lodges next door, at a fraction of the cost.', 'highlights' => ['Camping', 'Inside the park gate', 'Braai facilities', 'Early dune access']],

            // Vehicles
            ['type' => ListingType::Vehicle, 'name' => 'Bushtrack 4x4 Double Cab', 'region' => 'Khomas', 'lat' => -22.5700, 'lng' => 17.0836, 'price' => 950, 'featured' => false, 'description' => 'A rugged double-cab 4x4 with unlimited kilometres included — built for Namibia\'s gravel roads, no camping gear.', 'highlights' => ['Self-drive', '4x4', 'Unlimited km', 'GPS included']],
            ['type' => ListingType::Vehicle, 'name' => 'Bushtrack Camper 4x4', 'region' => 'Khomas', 'lat' => -22.5700, 'lng' => 17.0836, 'price' => 1450, 'featured' => true, 'description' => 'A 4x4 with a rooftop tent and full camping kit — fridge, stove, chairs — everything needed to pull off at any campsite along the route.', 'highlights' => ['Camper', '4x4', 'Rooftop tent, sleeps 2', 'Camping equipment included']],

            // Activities
            ['type' => ListingType::Activity, 'name' => 'Etosha Night Hide Game Drive', 'region' => 'Kunene', 'lat' => -19.1700, 'lng' => 15.9200, 'price' => 650, 'featured' => true, 'description' => 'Watch the Okaukuejo waterhole after dark from a lit viewing deck — rhino and lion sightings are common.'],
            ['type' => ListingType::Activity, 'name' => 'Sunrise Dune Climb at Deadvlei', 'region' => 'Hardap', 'lat' => -24.7592, 'lng' => 15.7092, 'price' => 350, 'featured' => true, 'description' => 'The classic Namibia photo: climbing Big Daddy before sunrise for the light hitting the white clay pan.'],
            ['type' => ListingType::Activity, 'name' => 'Dune Quad Biking', 'region' => 'Erongo', 'lat' => -22.6900, 'lng' => 14.5700, 'price' => 780, 'featured' => false, 'description' => 'Guided quad bike routes through the coastal dunes just outside Swakopmund.'],
            ['type' => ListingType::Activity, 'name' => 'Twyfelfontein Rock Art Walk', 'region' => 'Kunene', 'lat' => -20.5928, 'lng' => 14.3739, 'price' => 420, 'featured' => false, 'description' => 'A guided walk through one of Africa\'s largest concentrations of rock engravings, UNESCO-listed since 2007.'],
            ['type' => ListingType::Activity, 'name' => 'Desert-Adapted Elephant Tracking', 'region' => 'Kunene', 'lat' => -20.4500, 'lng' => 14.2000, 'price' => 1100, 'featured' => false, 'description' => 'Track one of the world\'s few desert-adapted elephant populations along the dry Huab riverbed.'],
            ['type' => ListingType::Activity, 'name' => 'Sandwich Harbour 4x4 Tour', 'region' => 'Erongo', 'lat' => -23.3500, 'lng' => 14.4667, 'price' => 950, 'featured' => false, 'description' => 'Towering dunes drop straight into the Atlantic — only accessible with an experienced 4x4 guide.'],
            ['type' => ListingType::Activity, 'name' => 'Fish River Canyon Sundowner Drive', 'region' => 'Karas', 'lat' => -27.5833, 'lng' => 17.6167, 'price' => 480, 'featured' => false, 'description' => 'Sundown at the second-largest canyon in the world, drink in hand at the main viewpoint.'],

            // Restaurants
            ['type' => ListingType::Restaurant, 'name' => 'Xwama Cultural Village', 'region' => 'Khomas', 'lat' => -22.5750, 'lng' => 17.0900, 'price' => 320, 'featured' => false, 'description' => 'Traditional Namibian dishes — oryx, mopane worms, potjiekos — in a lively cultural-village setting in Windhoek.'],
            ['type' => ListingType::Restaurant, 'name' => 'The Tug', 'region' => 'Erongo', 'lat' => -22.6800, 'lng' => 14.5283, 'price' => 480, 'featured' => true, 'description' => 'Seafood on a restored jetty over the Atlantic — Swakopmund\'s most-photographed dinner spot.'],
            ['type' => ListingType::Restaurant, 'name' => 'Ocean Cellar', 'region' => 'Erongo', 'lat' => -22.6767, 'lng' => 14.5250, 'price' => 550, 'featured' => false, 'description' => 'Fresh daily catch and an oceanfront terrace, a Swakopmund fine-dining regular.'],
            ['type' => ListingType::Restaurant, 'name' => 'Canyon Roadhouse Diner', 'region' => 'Karas', 'lat' => -27.5333, 'lng' => 17.8500, 'price' => 280, 'featured' => false, 'description' => 'Hearty roadhouse fare under a ceiling of vintage number plates and old car parts.'],
        ];

        foreach ($listings as $listing) {
            $partner = match ($listing['type']) {
                ListingType::Accommodation => Partner::where('name', $listing['name'])->first(),
                ListingType::Vehicle => Partner::where('name', 'Bushtrack Car & Camper Hire')->first(),
                default => null,
            };

            Listing::updateOrCreate(
                ['slug' => Str::slug($listing['name'])],
                [
                    'type' => $listing['type'],
                    'partner_id' => $partner?->id,
                    'name' => ['en' => $listing['name']],
                    'description' => ['en' => $listing['description']],
                    'highlights' => isset($listing['highlights']) ? ['en' => $listing['highlights']] : null,
                    'region' => $listing['region'],
                    'latitude' => $listing['lat'],
                    'longitude' => $listing['lng'],
                    'price_from' => $listing['price'],
                    'price_currency' => 'NAD',
                    'is_featured' => $listing['featured'],
                    'is_published' => true,
                    'accepts_inquiries' => true,
                ]
            );
        }
    }
}
