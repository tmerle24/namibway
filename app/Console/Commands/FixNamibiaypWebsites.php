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

    /**
     * Infra/CDN/tracking domains a listing's own website should never resolve
     * to — used only for --scan reporting. Deliberately excludes social
     * platforms (facebook.com, instagram.com, ...): many small Namibian
     * businesses genuinely have no site of their own beyond a Facebook page,
     * so those are legitimate, not bugs.
     */
    private const SUSPICIOUS_DOMAINS = [
        'googleapis.com', 'gstatic.com', 'googletagmanager.com', 'google-analytics.com',
        'doubleclick.net', 'bootstrapcdn.com', 'cloudflare.com', 'jsdelivr.net',
        'cdnjs.cloudflare.com', 'googleusercontent.com', 'gravatar.com', 'schema.org',
        'w3.org', 'jquery.com', 'fundingchoicesmessages.google.com',
    ];

    protected $signature = 'namibway:fix-namibiayp-websites
                            {--dry-run : Show what would change without writing}
                            {--scan    : Read-only — list any website pointing at a known CDN/tracking domain, without touching data}';

    protected $description = 'Null out the bad "ajax.googleapis.com" website value left by the old namibiayp scraper bug';

    public function handle(): int
    {
        if ($this->option('scan')) {
            return $this->scan();
        }

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

    private function scan(): int
    {
        $hits = Listing::whereNotNull('website')
            ->get(['id', 'slug', 'website'])
            ->filter(function (Listing $listing) {
                $host = parse_url((string) $listing->website, PHP_URL_HOST);

                if (! $host) {
                    return false;
                }

                foreach (self::SUSPICIOUS_DOMAINS as $domain) {
                    if (str_ends_with($host, $domain)) {
                        return true;
                    }
                }

                return false;
            });

        if ($hits->isEmpty()) {
            $this->info('No listings with a website pointing at a known CDN/tracking domain.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Slug', 'Website'],
            $hits->map(fn (Listing $l) => [$l->id, $l->slug, $l->website])
        );

        return self::SUCCESS;
    }
}
