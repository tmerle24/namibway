<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Listing;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Re-derives Listing::city_id from the free-text address field. The city_id
 * column was originally backfilled with one representative city per political
 * region (see 2026_08_02_180400_add_city_id_to_listings_table.php) purely as
 * a rough placeholder so nothing was left unset — this command replaces that
 * placeholder with the actual city named in each listing's address, where one
 * can be found.
 *
 * Matching: each city name (plus an ASCII-transliterated variant, for
 * addresses that drop diacritics — e.g. "Luderitz" for "Lüderitz") is matched
 * as a whole word/phrase against the address text, longest city name first,
 * so a multi-word city like "Walvis Bay" wins over any shorter substring
 * match. Listings whose address doesn't name a recognizable city keep their
 * current city_id untouched rather than being cleared — the rough regional
 * default is still better than nothing. Safe to rerun.
 */
class BackfillListingCities extends Command
{
    protected $signature = 'namibway:backfill-listing-cities
                            {--limit= : Only process this many listings (for a test run)}
                            {--dry-run : Show what would change without writing to DB}';

    protected $description = "Re-derive Listing::city_id from each listing's address text";

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($dry) {
            $this->warn('DRY RUN — no changes will be written');
        }

        $matchers = City::all(['id', 'name'])
            ->sortByDesc(fn (City $city) => mb_strlen($city->name))
            ->values()
            ->map(fn (City $city) => [
                'city' => $city,
                'pattern' => $this->buildPattern($city->name),
            ]);

        if ($matchers->isEmpty()) {
            $this->error('No cities found — seed the cities table first.');

            return self::FAILURE;
        }

        $query = Listing::whereNotNull('address')->where('address', '!=', '');
        $total = $query->count();

        if ($total === 0) {
            $this->info('No listings with an address — nothing to backfill.');

            return self::SUCCESS;
        }

        $toProcess = $limit ? min($limit, $total) : $total;
        $this->info("Found {$total} listings with an address".($limit ? ", processing {$toProcess}." : '.'));

        $updated = 0;
        $alreadyCorrect = 0;
        $noMatch = 0;
        $unmatchedSamples = [];
        $bar = $this->output->createProgressBar($toProcess);
        $bar->start();

        $query->select(['id', 'name', 'address', 'city_id'])
            ->chunkById(200, function ($listings) use (&$updated, &$alreadyCorrect, &$noMatch, &$unmatchedSamples, &$toProcess, $dry, $matchers, $bar) {
                foreach ($listings as $listing) {
                    if ($toProcess <= 0) {
                        return false;
                    }

                    $match = $this->matchCity($listing->address, $matchers);

                    if ($match === null) {
                        $noMatch++;

                        if (count($unmatchedSamples) < 15) {
                            $unmatchedSamples[] = "#{$listing->id} {$listing->name}: {$listing->address}";
                        }
                    } elseif ((int) $listing->city_id === $match->id) {
                        $alreadyCorrect++;
                    } else {
                        $updated++;

                        if (! $dry) {
                            $listing->forceFill(['city_id' => $match->id])->saveQuietly();
                        }
                    }

                    $toProcess--;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Updated', 'Already correct', 'No city found in address'],
            [[$updated, $alreadyCorrect, $noMatch]],
        );

        if ($unmatchedSamples !== []) {
            $this->newLine();
            $this->line('Sample addresses with no recognizable city (city_id left unchanged):');
            foreach ($unmatchedSamples as $sample) {
                $this->line('  '.$sample);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{city: City, pattern: string}>  $matchers
     */
    private function matchCity(string $address, $matchers): ?City
    {
        foreach ($matchers as $matcher) {
            if (preg_match($matcher['pattern'], $address) === 1) {
                return $matcher['city'];
            }
        }

        return null;
    }

    private function buildPattern(string $name): string
    {
        $variants = array_unique([$name, Str::ascii($name)]);
        $alternation = implode('|', array_map(fn ($variant) => preg_quote($variant, '/'), $variants));

        return '/(?<![\p{L}\p{N}])(?:'.$alternation.')(?![\p{L}\p{N}])/iu';
    }
}
