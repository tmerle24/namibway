<?php

namespace Database\Seeders;

use App\Models\Place;
use App\Models\RouteTemplate;
use App\Models\RouteTemplateStop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The classic Namibia self-drive routes, as sequences of places.
 *
 * Rewritten 2026-08-23 from four published Namibia self-drive route guides,
 * which describe the same anticlockwise loop with the same stops in the same
 * order. The shape is not a matter of taste — it is what the roads and the
 * distances allow, and every guide arrives at it independently.
 *
 * **They used to name regions, and that was the bug.** Kunene is 115,000 km²
 * and runs from the Skeleton Coast to the Angolan border, so "visit Kunene"
 * left the model free to pick any town in it. A real generated trip went
 * Windhoek → Otjiwarongo → the coast → Etosha → Khorixas with three legs over
 * eight hours: not a route anybody would drive, and not something the model
 * did wrong so much as something the template never said.
 *
 * Three rules the stop lists follow:
 *
 *  - **No leg over the daily driving cap.** Where two highlights are further
 *    apart than a morning's drive there is a night in between — Keetmanshoop
 *    before Fish River Canyon, Solitaire between Sossusvlei and the coast.
 *    Gravel roads, no driving after dark and help a long way off are what
 *    make that a safety rule rather than a comfort one.
 *  - **Two nights where the point is to stay.** Sossusvlei, Swakopmund,
 *    Twyfelfontein and Etosha are not stopovers; one night at Sossusvlei means
 *    arriving after the dunes close and leaving before they open.
 *  - **Etosha is crossed west to east**, which is how the park is driven, and
 *    puts a camp at each end instead of doubling back.
 *
 * Stops are the loop body *between* the Windhoek start/end bookend days —
 * ItineraryService adds those separately whichever template is chosen.
 *
 * The old region-level note about Karas being unreachable is gone with the
 * regions: at region granularity Hardap→Karas was 6.7h and over the cap, but
 * the places inside them are two hours apart, so the south is simply part of
 * the long loop now.
 */
class RouteTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $places = Place::query()->with('region')->get()->keyBy('slug');

        foreach (self::templates() as $template) {
            $model = RouteTemplate::firstOrNew(['slug' => Str::slug($template['name'])]);

            // HasTranslations::setTranslations() merges into whatever locales
            // already exist rather than replacing them — same reasoning as
            // RegionSeeder: forget first so a stray hand-added translation
            // doesn't silently survive a reseed over this English-only copy.
            $model->forgetTranslations('name');

            $model->fill([
                'name' => ['en' => $template['name']],
                'trip_type' => $template['trip_type'],
                'min_nights' => $template['min_nights'],
                'max_nights' => $template['max_nights'],
                'notes' => $template['notes'],
                'sort_order' => $template['sort'],
                'is_published' => data_get($template, 'published', true),
            ])->save();

            $model->stops()->delete();

            $order = 0;

            foreach ($template['stops'] as $stop) {
                /** @var Place|null $place */
                $place = $places->get($stop['place']);

                // A place we do not hold a row for is skipped rather than
                // written region-only: a stop nobody can resolve is worse than
                // a shorter route, because the model will fill the gap itself.
                if ($place === null) {
                    continue;
                }

                RouteTemplateStop::create([
                    'route_template_id' => $model->id,
                    'place_id' => $place->id,
                    // Still written: the macro ROUTE guidance is region-level,
                    // and a stop has to be able to answer "which part of the
                    // country is this leg in".
                    'region' => $place->region->name,
                    'min_nights' => $stop['min_nights'],
                    'max_nights' => $stop['max_nights'],
                    'highlights' => $stop['highlights'],
                    'sort_order' => $order++,
                ]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function templates(): array
    {
        return [
            [
                'name' => 'Classic Safari Loop',
                'trip_type' => 'safari',
                'min_nights' => 8,
                'max_nights' => 16,
                'notes' => 'The two-week route every guide opens with — desert, coast, rock art and Etosha in one continuous anticlockwise pass, with no leg longer than a morning.',
                'sort' => 10,
                'stops' => [
                    ['place' => 'sesriem', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'Sossusvlei and Deadvlei at sunrise, Dune 45, Sesriem Canyon'],
                    ['place' => 'swakopmund', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'The coast, Walvis Bay lagoon, Sandwich Harbour, the only town on the route with restaurants worth planning around'],
                    ['place' => 'twyfelfontein', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Rock engravings, Organ Pipes, Burnt Mountain, desert-adapted elephant in the Huab'],
                    ['place' => 'etosha-national-park', 'min_nights' => 3, 'max_nights' => 4, 'highlights' => 'Waterholes at dawn and dusk, west to east across the park, the floodlit hide at Okaukuejo after dark'],
                    ['place' => 'waterberg-plateau-park', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'A walk up the plateau, breaking the last long drive back'],
                ],
            ],
            [
                'name' => 'Etosha Quick Escape',
                'trip_type' => 'safari',
                'min_nights' => 4,
                'max_nights' => 7,
                'notes' => 'For a short trip — no time to cross the country, so one unmissable thing done properly rather than four done badly.',
                'sort' => 20,
                'stops' => [
                    ['place' => 'otjiwarongo', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'Cheetah country, and half the drive north already behind you'],
                    ['place' => 'etosha-national-park', 'min_nights' => 2, 'max_nights' => 4, 'highlights' => 'Two full days of game drives, west to east'],
                ],
            ],
            [
                'name' => 'Extended Namibia Loop',
                'trip_type' => 'adventure',
                'min_nights' => 17,
                'max_nights' => 24,
                'notes' => 'The classic loop with the Kalahari at the start, a night in the Erongo granite and the quiet eastern edge of Etosha — depth in the same country rather than more of it.',
                'sort' => 30,
                'stops' => [
                    ['place' => 'mariental', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Red Kalahari dunes and the first proper night sky'],
                    ['place' => 'sesriem', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'Sossusvlei, Deadvlei, Dune 45'],
                    ['place' => 'solitaire', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'Apple pie, the Kuiseb pass, and a short day between two long ones'],
                    ['place' => 'swakopmund', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'The coast; Sandwich Harbour and the Walvis Bay lagoon from here'],
                    ['place' => 'spitzkoppe', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Granite, rock art, and the darkest sky on the route'],
                    ['place' => 'twyfelfontein', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Engravings, Burnt Mountain, the Petrified Forest at Khorixas'],
                    ['place' => 'etosha-national-park', 'min_nights' => 3, 'max_nights' => 4, 'highlights' => 'West to east across the park'],
                    ['place' => 'onguma-nature-reserve', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The quiet eastern edge, outside the park gates'],
                    ['place' => 'waterberg-plateau-park', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The plateau, and the last stop before town'],
                ],
            ],
            [
                'name' => 'Grand Namibia Loop',
                'trip_type' => 'adventure',
                'min_nights' => 18,
                'max_nights' => 28,
                'notes' => 'The whole country for three weeks and more: the Kalahari, the deep south to Fish River Canyon and Lüderitz, then north up the coast to Damaraland and Etosha.',
                'sort' => 40,
                'stops' => [
                    ['place' => 'mariental', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Kalahari dunes'],
                    ['place' => 'keetmanshoop', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'Quiver Tree Forest and Giants Playground at last light'],
                    ['place' => 'fish-river-canyon', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The main viewpoint in the last hour of sun; Ai-Ais hot springs below'],
                    ['place' => 'luderitz', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Kolmanskop in the morning light, Diaz Point, the wild horses at Garub on the way in'],
                    ['place' => 'sesriem', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'Sossusvlei and Deadvlei'],
                    ['place' => 'swakopmund', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'The coast'],
                    ['place' => 'cape-cross', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'The seal colony, and the salt road north'],
                    ['place' => 'twyfelfontein', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Damaraland, rock engravings, desert elephant'],
                    ['place' => 'etosha-national-park', 'min_nights' => 3, 'max_nights' => 4, 'highlights' => 'West to east across the park'],
                    ['place' => 'waterberg-plateau-park', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The plateau'],
                ],
            ],
            [
                // Zambezi is the most remote corner of the country: every
                // neighbour except the Kavangos is a day and a half away. Rundu
                // is therefore an explicit overnight bridge in both directions,
                // rather than something the model has to improvise.
                'name' => 'Caprivi Green North Extension',
                'trip_type' => 'adventure',
                'min_nights' => 21,
                'max_nights' => 32,
                'notes' => 'Three weeks and more, ending in the one green part of Namibia: rivers, elephant and hippo on the Botswana border. Skips the far south to keep the driving days sane.',
                'sort' => 50,
                'stops' => [
                    ['place' => 'sesriem', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'Sossusvlei and Deadvlei'],
                    ['place' => 'swakopmund', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'The coast'],
                    ['place' => 'twyfelfontein', 'min_nights' => 2, 'max_nights' => 2, 'highlights' => 'Damaraland'],
                    ['place' => 'etosha-national-park', 'min_nights' => 3, 'max_nights' => 4, 'highlights' => 'West to east across the park'],
                    ['place' => 'grootfontein', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'The Hoba meteorite, and the turn east'],
                    ['place' => 'rundu', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The Okavango, and the first green on the whole trip'],
                    ['place' => 'katima-mulilo', 'min_nights' => 2, 'max_nights' => 3, 'highlights' => 'Zambezi floodplains, elephant and hippo; Chobe and the falls an hour further'],
                    ['place' => 'rundu', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'The riverside again, westbound — the bridge that keeps the return legal'],
                    ['place' => 'otjiwarongo', 'min_nights' => 1, 'max_nights' => 1, 'highlights' => 'The last night before Windhoek'],
                ],
            ],
        ];
    }
}
