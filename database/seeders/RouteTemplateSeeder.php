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
                    ['region' => 'Kunene', 'min_nights' => 3, 'max_nights' => 5, 'highlights' => 'Etosha safari, waterhole game viewing'],
                    ['region' => 'Erongo', 'min_nights' => 2, 'max_nights' => 4, 'highlights' => 'Spitzkoppe, Skeleton Coast, Swakopmund'],
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
