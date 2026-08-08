<?php

namespace Database\Seeders;

use App\Models\RouteTemplate;
use App\Models\RouteTemplateStop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RouteTemplateSeeder extends Seeder
{
    /**
     * Starter "classic route" content so ItineraryService::matchingRouteTemplates()
     * has real candidates out of the box, and so Anna has real examples to
     * edit in Filament rather than an empty table. Stops are the loop body
     * *between* the Windhoek start/end bookend days — ItineraryService adds
     * those separately regardless of which template (if any) is chosen.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Classic Safari Loop',
                'trip_type' => 'safari',
                'min_nights' => 8,
                'max_nights' => 16,
                'notes' => 'The default round-trip loop for most travelers — wildlife, desert scenery and coast in one continuous pass.',
                'sort' => 10,
                'stops' => [
                    ['region' => 'Otjozondjupa', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Waterberg Plateau hiking'],
                    ['region' => 'Kunene', 'min_nights' => 3, 'max_nights' => 5, 'highlights' => 'Etosha safari, waterhole game viewing, Damaraland, Twyfelfontein rock engravings'],
                    ['region' => 'Erongo', 'min_nights' => 2, 'max_nights' => 4, 'highlights' => 'Spitzkoppe, Erongo Mountains, Skeleton Coast, Swakopmund'],
                    ['region' => 'Hardap', 'min_nights' => 2, 'max_nights' => 4, 'highlights' => 'Sossusvlei, Sesriem, Naukluft'],
                ],
            ],
            [
                'name' => 'Etosha Quick Escape',
                'trip_type' => 'safari',
                'min_nights' => 4,
                'max_nights' => 7,
                'notes' => 'For short trips — no time to cover the whole country, so stay focused on one unmissable highlight.',
                'sort' => 20,
                'stops' => [
                    ['region' => 'Kunene', 'min_nights' => 2, 'max_nights' => 5, 'highlights' => 'Etosha safari, waterhole game viewing'],
                ],
            ],
            [
                'name' => 'Extended Namibia Loop',
                'trip_type' => 'adventure',
                'min_nights' => 17,
                'max_nights' => 24,
                // NOT extended into Karas (Fish River Canyon / Lüderitz): every
                // DRIVING_HOURS pair touching Karas exceeds MAX_DRIVING_HOURS
                // (closest is Hardap|Karas at 6.7h) — it can't be reached from or
                // returned to this loop within the daily driving-time limit at
                // any trip length, so extra nights buy depth in the same 4
                // regions instead of a 5th region.
                'notes' => 'For travelers with real time to spare — goes deeper into the same classic loop (longer stays, more day trips per region) rather than adding new regions.',
                'sort' => 30,
                'stops' => [
                    ['region' => 'Otjozondjupa', 'min_nights' => 1, 'max_nights' => 3, 'highlights' => 'Waterberg Plateau hiking'],
                    ['region' => 'Kunene', 'min_nights' => 4, 'max_nights' => 8, 'highlights' => 'Etosha safari, waterhole game viewing, Damaraland'],
                    ['region' => 'Erongo', 'min_nights' => 3, 'max_nights' => 6, 'highlights' => 'Spitzkoppe, Skeleton Coast, Swakopmund'],
                    ['region' => 'Hardap', 'min_nights' => 3, 'max_nights' => 6, 'highlights' => 'Sossusvlei, Sesriem, Naukluft'],
                ],
            ],
            [
                // The classic loop above deliberately stays out of Karas — that
                // decision predates the per-city driving-hours backfill
                // (2026-08-04) and was based on the coarse REGION-level
                // Hardap|Karas figure (6.7h, over the 6h cap). The actual
                // city_driving_hours table now has a same-region-cheap bridge
                // (Mariental<->Keetmanshoop, 2.44h) that makes Karas reachable
                // within the per-day cap after all — see ItineraryService's
                // cityDrivingHours() which is checked before the region
                // fallback. Hardap appears twice: once northbound (Sossusvlei)
                // and once again as the final stop before Windhoek (Kalahari,
                // near Mariental) — that second Hardap leg is what keeps the
                // Karas->Windhoek return under the driving-time cap, instead of
                // jumping straight from Karas to Khomas (7.3h, over cap).
                'name' => 'Grand Namibia Loop',
                'trip_type' => 'adventure',
                'min_nights' => 18,
                'max_nights' => 28,
                'notes' => 'The full country loop for travelers with 3+ weeks — everything in the Classic Safari Loop plus a deep run south to Fish River Canyon, the Kalahari and Lüderitz.',
                'sort' => 40,
                'stops' => [
                    ['region' => 'Otjozondjupa', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Waterberg Plateau hiking'],
                    ['region' => 'Kunene', 'min_nights' => 3, 'max_nights' => 6, 'highlights' => 'Etosha safari, waterhole game viewing, Damaraland, Twyfelfontein rock engravings'],
                    ['region' => 'Erongo', 'min_nights' => 2, 'max_nights' => 4, 'highlights' => 'Spitzkoppe, Erongo Mountains, Skeleton Coast, Swakopmund'],
                    ['region' => 'Hardap', 'min_nights' => 1, 'max_nights' => 3, 'highlights' => 'Sossusvlei, Sesriem, Naukluft'],
                    ['region' => 'Karas', 'min_nights' => 4, 'max_nights' => 7, 'highlights' => 'Fish River Canyon, quiver tree forest, Lüderitz, Kolmanskop ghost town, wild desert horses'],
                    ['region' => 'Hardap', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Kalahari Desert (Mariental area), final stop before Windhoek'],
                ],
            ],
            [
                // Zambezi (Caprivi) is the single most remote region in the
                // DRIVING_HOURS table (10.4h+ from every neighbor except
                // Kavango East/West) — no direct day-leg from or to it is ever
                // safe, region-fallback or city-level. Kavango East appears
                // twice (outbound and return) specifically so Claude has an
                // explicit overnight bridge in both directions instead of
                // improvising one; Zambezi<->Kavango East is 5.4h, within cap.
                'name' => 'Caprivi Green North Extension',
                'trip_type' => 'adventure',
                'min_nights' => 21,
                'max_nights' => 32,
                'notes' => 'Only for travelers with 3+ weeks — extends the classic north loop into the water-rich, tropical Zambezi (Caprivi) region: elephants, hippos and river life near the Botswana border. Skips the coast to keep total driving time reasonable.',
                'sort' => 50,
                'stops' => [
                    ['region' => 'Otjozondjupa', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Waterberg Plateau hiking'],
                    ['region' => 'Kunene', 'min_nights' => 3, 'max_nights' => 6, 'highlights' => 'Etosha safari, waterhole game viewing'],
                    ['region' => 'Kavango East', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Popa Falls, Okavango River, Bwabwata National Park (west)'],
                    ['region' => 'Zambezi', 'min_nights' => 3, 'max_nights' => 6, 'highlights' => 'Caprivi wetlands, elephants and hippos, Chobe/Zambezi river confluence, day trips toward the Botswana border'],
                    ['region' => 'Kavango East', 'min_nights' => 1, 'max_nights' => 2, 'highlights' => 'Return via Rundu, Okavango riverside'],
                ],
            ],
        ];

        foreach ($templates as $template) {
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
                'is_published' => true,
            ])->save();

            $model->stops()->delete();

            foreach ($template['stops'] as $index => $stop) {
                RouteTemplateStop::create([
                    'route_template_id' => $model->id,
                    'region' => $stop['region'],
                    'min_nights' => $stop['min_nights'],
                    'max_nights' => $stop['max_nights'],
                    'highlights' => $stop['highlights'],
                    'sort_order' => $index,
                ]);
            }
        }
    }
}
