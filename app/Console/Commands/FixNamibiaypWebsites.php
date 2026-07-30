<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Console\Command;

/**
 * One-off cleanup for a namibiayp import bug: the website-extraction fallback
 * used to grab the first external <a href> on the page, which on every
 * namibiayp.com company page is a <link rel="preconnect" href="https://ajax.googleapis.com">
 * in the <head> — not the business's real website. Nulls out that bad value
 * so those listings/partners are treated as having no website again.
 */
class FixNamibiaypWebsites extends Command
{
    private const BAD_VALUE = 'https://ajax.googleapis.com';

    protected $signature = 'namibway:fix-namibiayp-websites {--dry-run : Show what would change without writing}';

    protected $description = 'Null out the bad "ajax.googleapis.com" website value left by the old namibiayp scraper bug';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $listings = Listing::where('website', self::BAD_VALUE)->count();
        $partners = Partner::where('website', self::BAD_VALUE)->count();

        if ($dry) {
            $this->warn('DRY RUN — nothing will be changed');
            $this->info("Would clear website on {$listings} listing(s) and {$partners} partner(s).");

            return self::SUCCESS;
        }

        Listing::where('website', self::BAD_VALUE)->update(['website' => null]);
        Partner::where('website', self::BAD_VALUE)->update(['website' => null]);

        $this->info("Cleared website on {$listings} listing(s) and {$partners} partner(s).");

        return self::SUCCESS;
    }
}
