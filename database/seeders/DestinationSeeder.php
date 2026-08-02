<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DestinationSeeder extends Seeder
{
    /**
     * Named destinations shown as cards in TopDestinations.vue (homepage, under
     * the hero) and in the Explore section's "Regions to explore" row. Each maps
     * to one of the 14 political regions used on Listing::region so clicking a
     * card can filter the catalog correctly — most of these are well-known
     * sub-areas (a town, park, or landmark) rather than the region itself, since
     * travelers recognize "Sossusvlei" or "Spitzkoppe", not "Hardap" or "Erongo".
     * Only the original 6 have destination cards seeded so far — the 8 later-
     * added regions (mainly the populous north and the Zambezi/Caprivi strip)
     * have no partner content or curated highlight yet.
     */
    private const OBSOLETE_SLUGS = ['khomas', 'erongo', 'hardap', 'kunene', 'otjozondjupa', 'karas'];

    public function run(): void
    {
        $destinations = [
            ['name' => 'Windhoek',          'region' => 'Khomas',       'lat' => -22.5597, 'lng' => 17.0832, 'blurb' => "Namibia's capital — colonial architecture, cafés, and the start of most itineraries.",                'image' => 'regions/windhoek.jpg',          'sort' => 10],
            ['name' => 'Etosha',            'region' => 'Kunene',       'lat' => -18.8550, 'lng' => 16.3290, 'blurb' => "Namibia's premier wildlife park — waterhole game viewing at its very best.",                           'image' => 'regions/etosha.jpg',            'sort' => 20],
            ['name' => 'Sossusvlei',        'region' => 'Hardap',       'lat' => -24.7333, 'lng' => 15.8000, 'blurb' => 'Towering red dunes and the otherworldly dead trees of Deadvlei.',                                        'image' => 'regions/sossusvlei.jpg',        'sort' => 30],
            ['name' => 'Swakopmund',        'region' => 'Erongo',       'lat' => -22.6784, 'lng' => 14.5266, 'blurb' => 'A German colonial seaside town between the dunes and the Atlantic — adventure sports HQ.',               'image' => 'regions/swakopmund.jpg',        'sort' => 40],
            ['name' => 'Spitzkoppe',        'region' => 'Erongo',       'lat' => -21.8333, 'lng' => 15.1833, 'blurb' => "Namibia's 'Matterhorn' — granite peaks, ancient rock art, and some of the best stargazing on Earth.",       'image' => 'regions/spitzkoppe.jpg',        'sort' => 50],
            ['name' => 'Sandwich Harbour',  'region' => 'Erongo',       'lat' => -23.3500, 'lng' => 14.4500, 'blurb' => 'Where towering dunes plunge straight into the Atlantic — accessible only by 4x4.',                        'image' => 'regions/sandwich-harbour.jpg',  'sort' => 60],
            ['name' => 'Skeleton Coast',    'region' => 'Kunene',       'lat' => -20.5000, 'lng' => 13.3000, 'blurb' => 'Fog, seal colonies, and shipwrecks along one of the world\'s most remote coastlines.',                    'image' => 'regions/skeleton-coast.jpg',    'sort' => 70],
            ['name' => 'Twyfelfontein',     'region' => 'Kunene',       'lat' => -20.5975, 'lng' => 14.3742, 'blurb' => "Damaraland's UNESCO-listed rock engravings amid rugged desert-adapted wildlife country.",                'image' => 'regions/twyfelfontein.jpg',     'sort' => 80],
            ['name' => 'Waterberg',         'region' => 'Otjozondjupa', 'lat' => -20.4000, 'lng' => 17.2333, 'blurb' => 'A red sandstone plateau rising from the bushveld — hiking trails and rare species.',                     'image' => 'regions/waterberg.jpg',         'sort' => 90],
            ['name' => 'Fish River Canyon', 'region' => 'Karas',        'lat' => -27.5667, 'lng' => 17.6167, 'blurb' => "Africa's largest canyon — dramatic cliffside views and a legendary multi-day hike.",                     'image' => 'regions/fish-river-canyon.jpg', 'sort' => 100],
            ['name' => 'Lüderitzbucht',     'region' => 'Karas',        'lat' => -26.6481, 'lng' => 15.1594, 'blurb' => 'A colourful German colonial harbour town on the edge of the Namib, gateway to Kolmanskop.',                'image' => 'regions/luderitz.jpg',          'sort' => 110],
            ['name' => 'Kolmanskop',        'region' => 'Karas',        'lat' => -26.6975, 'lng' => 15.2181, 'blurb' => 'A diamond-boom ghost town slowly being reclaimed by the desert sand.',                                    'image' => 'regions/kolmanskop.jpg',        'sort' => 120],
        ];

        $regionIds = Region::pluck('id', 'name');

        foreach ($destinations as $destination) {
            $model = Destination::firstOrNew(['slug' => Str::slug($destination['name'])]);

            // HasTranslations::setTranslations() merges into whatever locales
            // already exist rather than replacing them, so a stray translation
            // added by hand via Filament would otherwise survive a reseed and
            // silently override the (deliberately English-only) copy below.
            $model->forgetTranslations('name');
            $model->forgetTranslations('blurb');

            $model->fill([
                'name' => ['en' => $destination['name']],
                'blurb' => ['en' => $destination['blurb']],
                'image' => $destination['image'],
                'region_id' => $regionIds[$destination['region']],
                'lat' => $destination['lat'],
                'lng' => $destination['lng'],
                'sort_order' => $destination['sort'],
                'is_published' => true,
            ])->save();
        }

        Destination::whereIn('slug', self::OBSOLETE_SLUGS)->delete();
    }
}
