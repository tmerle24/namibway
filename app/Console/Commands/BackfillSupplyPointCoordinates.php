<?php

namespace App\Console\Commands;

use App\Models\SupplyPoint;
use Illuminate\Console\Command;

/**
 * Gives a supply point the coordinates of the town or place it sits in.
 *
 * No geocoding, unlike its neighbours: a supply point is already filed against
 * a city or a place that has been geocoded, and "the fuel in Kamanjab is at
 * Kamanjab" is precise enough for a rule that measures gaps in hundreds of
 * kilometres. Typing fifty coordinate pairs from memory to say the same thing
 * would only add a way to be wrong.
 *
 * Needed because the seed migration runs whenever it runs — including on a
 * database whose cities have not been geocoded yet, where it can copy nothing.
 * This is the second half of that, and it is safe to run at any time: it only
 * ever fills a blank, so a coordinate somebody sharpened by hand to the actual
 * forecourt is never overwritten by the middle of the town.
 */
class BackfillSupplyPointCoordinates extends Command
{
    protected $signature = 'namibway:backfill-supply-point-coordinates
                            {--dry-run : Show what would be filled without writing}';

    protected $description = 'Fill missing lat/lng on supply points from the town or place they are filed under';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $points = SupplyPoint::query()
            ->where(fn ($query) => $query->whereNull('lat')->orWhereNull('lng'))
            ->with(['place:id,lat,lng', 'city:id,lat,lng'])
            ->orderBy('name')
            ->get();

        if ($points->isEmpty()) {
            $this->info('Every supply point already has coordinates.');

            return self::SUCCESS;
        }

        $filled = 0;
        $stranded = [];

        foreach ($points as $point) {
            // Places first, then cities — the same precedence
            // App\Services\Routing\RoutePointResolver uses.
            $anchor = null;

            foreach ([$point->place, $point->city] as $candidate) {
                if ($candidate !== null && $candidate->lat !== null && $candidate->lng !== null) {
                    $anchor = $candidate;

                    break;
                }
            }

            if ($anchor === null) {
                $stranded[] = $point->name;

                continue;
            }

            $this->line("{$point->name} → {$anchor->lat}, {$anchor->lng}");

            if (! $dry) {
                $point->update(['lat' => $anchor->lat, 'lng' => $anchor->lng]);
            }

            $filled++;
        }

        $this->info("Filled {$filled} of {$points->count()}.");

        if ($stranded !== []) {
            // Not a failure: it is a row waiting on its town being geocoded,
            // and saying which ones is the whole use of the message.
            $this->warn('No coordinates to copy for: '.implode(', ', $stranded));
        }

        return self::SUCCESS;
    }
}
