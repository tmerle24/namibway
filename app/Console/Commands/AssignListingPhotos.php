<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Command;

/**
 * Assigns curated Unsplash photo URLs to existing hand-seeded listings.
 * Run once before a presentation or when listings have no images.
 * Only updates listings that currently have no image set.
 */
class AssignListingPhotos extends Command
{
    protected $signature = 'listings:assign-photos
                            {--force : Overwrite existing photos}
                            {--dry-run : Show what would be updated without saving}';

    protected $description = 'Assign curated photos to existing listings that have no image';

    // Curated Unsplash photo IDs mapped to listing slugs.
    // Each entry: 'slug' => ['hero-id', 'gallery-id-1', 'gallery-id-2', ...]
    // Format: https://images.unsplash.com/photo-{id}?w=1200&q=80&auto=format&fit=crop
    private const PHOTO_MAP = [
        // Accommodation
        'olive-grove-guesthouse' => [
            'hero' => 'photo-1584132967334-10e028bd69f7', // guesthouse with garden
            'gallery' => ['photo-1566073771259-6a8506099945', 'photo-1520250497591-112f2f40a3f4'],
        ],
        'etosha-safari-lodge' => [
            'hero' => 'photo-1516026672322-bc52d61a55d5', // safari lodge Namibia/Africa
            'gallery' => ['photo-1547471080-7cc2caa01a7e', 'photo-1561131668-f63504deeff0'],
        ],
        'ongava-tented-camp' => [
            'hero' => 'photo-1501436513145-30f24e19fcc8', // luxury tented camp
            'gallery' => ['photo-1522878129833-838a904a0e9e', 'photo-1516026672322-bc52d61a55d5'],
        ],
        'the-delight-hotel' => [
            'hero' => 'photo-1571896349842-33c89424de2d', // boutique hotel pool
            'gallery' => ['photo-1566073771259-6a8506099945', 'photo-1584132967334-10e028bd69f7'],
        ],
        'doro-nawas-camp' => [
            'hero' => 'photo-1565967511849-76a60a516170', // desert camp Namibia
            'gallery' => ['photo-1561131668-f63504deeff0', 'photo-1501436513145-30f24e19fcc8'],
        ],
        'sossus-dune-lodge' => [
            'hero' => 'photo-1547471080-7cc2caa01a7e', // Sossusvlei dunes
            'gallery' => ['photo-1548948487-421c5b8ef4ee', 'photo-1565967511849-76a60a516170'],
        ],
        'little-kulala' => [
            'hero' => 'photo-1548948487-421c5b8ef4ee', // Namib desert luxury
            'gallery' => ['photo-1547471080-7cc2caa01a7e', 'photo-1565967511849-76a60a516170'],
        ],
        'canyon-roadhouse' => [
            'hero' => 'photo-1469854523086-cc02fe5d8800', // Fish River Canyon
            'gallery' => ['photo-1553697388-94e804e2f0f6', 'photo-1605649461784-efd271977753'],
        ],
        'etosha-village-campsite' => [
            'hero' => 'photo-1553697388-94e804e2f0f6', // Etosha campsite
            'gallery' => ['photo-1516026672322-bc52d61a55d5', 'photo-1561131668-f63504deeff0'],
        ],
        'sesriem-oshana-campsite' => [
            'hero' => 'photo-1565967511849-76a60a516170', // Namib campsite under stars
            'gallery' => ['photo-1547471080-7cc2caa01a7e', 'photo-1548948487-421c5b8ef4ee'],
        ],

        // Activities
        'sandwich-harbour-4x4-tour' => [
            'hero' => 'photo-1548948487-421c5b8ef4ee', // dunes meeting ocean
            'gallery' => ['photo-1547471080-7cc2caa01a7e', 'photo-1565967511849-76a60a516170'],
        ],
        'etosha-night-hide-game-drive' => [
            'hero' => 'photo-1561131668-f63504deeff0', // Etosha wildlife at night waterhole
            'gallery' => ['photo-1516026672322-bc52d61a55d5', 'photo-1553697388-94e804e2f0f6'],
        ],
        'sunrise-dune-climb-at-deadvlei' => [
            'hero' => 'photo-1547471080-7cc2caa01a7e', // Deadvlei sunrise
            'gallery' => ['photo-1548948487-421c5b8ef4ee', 'photo-1565967511849-76a60a516170'],
        ],
        'dune-quad-biking' => [
            'hero' => 'photo-1493246507139-91e8fad9978e', // quad biking dunes
            'gallery' => ['photo-1547471080-7cc2caa01a7e', 'photo-1548948487-421c5b8ef4ee'],
        ],
        'twyfelfontein-rock-art-walk' => [
            'hero' => 'photo-1619546813926-a78fa6372cd2', // rock formations Namibia
            'gallery' => ['photo-1565967511849-76a60a516170', 'photo-1565363887862-f3e3b0a476b5'],
        ],
        'desert-adapted-elephant-tracking' => [
            'hero' => 'photo-1603513492128-ba7bc9b3e143', // desert elephants
            'gallery' => ['photo-1561131668-f63504deeff0', 'photo-1516026672322-bc52d61a55d5'],
        ],
        'fish-river-canyon-sundowner-drive' => [
            'hero' => 'photo-1469854523086-cc02fe5d8800', // Fish River Canyon sunset
            'gallery' => ['photo-1605649461784-efd271977753', 'photo-1553697388-94e804e2f0f6'],
        ],

        // Restaurants
        'the-tug' => [
            'hero' => 'photo-1414235077428-338989a2e8c0', // waterfront restaurant
            'gallery' => ['photo-1555396273-367ea4eb4db5', 'photo-1517248135467-4c7edcad34c4'],
        ],
        'xwama-cultural-village' => [
            'hero' => 'photo-1565363887862-f3e3b0a476b5', // African cultural village
            'gallery' => ['photo-1619546813926-a78fa6372cd2', 'photo-1553697388-94e804e2f0f6'],
        ],
        'ocean-cellar' => [
            'hero' => 'photo-1517248135467-4c7edcad34c4', // wine cellar restaurant
            'gallery' => ['photo-1414235077428-338989a2e8c0', 'photo-1555396273-367ea4eb4db5'],
        ],
        'canyon-roadhouse-diner' => [
            'hero' => 'photo-1555396273-367ea4eb4db5', // roadhouse diner
            'gallery' => ['photo-1414235077428-338989a2e8c0', 'photo-1517248135467-4c7edcad34c4'],
        ],

        // Vehicles
        'bushtrack-4x4-double-cab' => [
            'hero' => 'photo-1469854523086-cc02fe5d8800', // 4x4 on dirt road Namibia
            'gallery' => ['photo-1605649461784-efd271977753', 'photo-1548948487-421c5b8ef4ee'],
        ],
        'bushtrack-camper-4x4' => [
            'hero' => 'photo-1605649461784-efd271977753', // camper van Namibia
            'gallery' => ['photo-1469854523086-cc02fe5d8800', 'photo-1547471080-7cc2caa01a7e'],
        ],
    ];

    private const UNSPLASH_BASE = 'https://images.unsplash.com/';

    private const UNSPLASH_PARAMS = '?w=1200&q=80&auto=format&fit=crop';

    public function handle(): int
    {
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $listings = Listing::all();
        $updated = 0;
        $skipped = 0;

        foreach ($listings as $listing) {
            if ($listing->image && ! $force) {
                $this->line("  skip {$listing->slug} (already has image)");
                $skipped++;

                continue;
            }

            $map = self::PHOTO_MAP[$listing->slug] ?? null;

            if (! $map) {
                // Fallback: pick by type
                $map = $this->fallbackByType($listing->type->value);
                $this->line("  fallback for {$listing->slug} (no curated map)");
            }

            $heroUrl = self::UNSPLASH_BASE.$map['hero'].self::UNSPLASH_PARAMS;
            $galleryUrls = array_map(
                fn ($id) => self::UNSPLASH_BASE.$id.self::UNSPLASH_PARAMS,
                $map['gallery'] ?? []
            );

            if ($dryRun) {
                $this->line("  [dry-run] {$listing->slug} → {$heroUrl}");
                $updated++;

                continue;
            }

            $listing->image = $heroUrl;
            $listing->gallery = $galleryUrls;
            $listing->save();

            $this->line("  ✓ {$listing->slug}");
            $updated++;
        }

        $this->info("\nDone: {$updated} updated, {$skipped} skipped");

        return self::SUCCESS;
    }

    private function fallbackByType(string $type): array
    {
        return match ($type) {
            'accommodation' => [
                'hero' => 'photo-1566073771259-6a8506099945',
                'gallery' => ['photo-1584132967334-10e028bd69f7', 'photo-1520250497591-112f2f40a3f4'],
            ],
            'restaurant' => [
                'hero' => 'photo-1414235077428-338989a2e8c0',
                'gallery' => ['photo-1555396273-367ea4eb4db5', 'photo-1517248135467-4c7edcad34c4'],
            ],
            'vehicle' => [
                'hero' => 'photo-1469854523086-cc02fe5d8800',
                'gallery' => ['photo-1605649461784-efd271977753'],
            ],
            default => [ // activity
                'hero' => 'photo-1547471080-7cc2caa01a7e',
                'gallery' => ['photo-1548948487-421c5b8ef4ee', 'photo-1565967511849-76a60a516170'],
            ],
        };
    }
}
