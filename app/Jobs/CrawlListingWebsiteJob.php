<?php

namespace App\Jobs;

use App\Enums\ContentSource;
use App\Models\Listing;
use App\Services\Enrichment\WebsiteContentExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Crawls a single listing's own website for og:/meta tags and photos
 * (legal-friendly, owner-published data) and stores what it finds on R2.
 * Dispatched repeatedly over time by namibway:scrape-websites so listings
 * get re-checked periodically rather than only ever once.
 */
class CrawlListingWebsiteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 45;

    /** Dedupe window: don't queue the same listing twice while one crawl is in flight/pending. */
    public int $uniqueFor = 3600;

    /** Max images (hero + gallery) to download per listing. */
    private const MAX_IMAGES = 6;

    public function __construct(public readonly int $listingId) {}

    public function uniqueId(): string
    {
        return (string) $this->listingId;
    }

    public function handle(WebsiteContentExtractor $extractor): void
    {
        $listing = Listing::find($this->listingId);

        if (! $listing || ! $listing->website) {
            return;
        }

        $host = parse_url($listing->website, PHP_URL_HOST);

        if (! $host) {
            $this->markScraped($listing);

            return;
        }

        if (! $extractor->claimHostSlot($host)) {
            $this->release(WebsiteContentExtractor::HOST_COOLDOWN_SECONDS);

            return;
        }

        if (! $extractor->robotsAllow($listing->website)) {
            return; // don't mark as scraped — retry on the next refresh cycle
        }

        $html = $extractor->fetchHomepage($listing->website);

        if (! $html) {
            $this->markScraped($listing);

            return;
        }

        $og = $extractor->extractSignals($html, $listing->website);
        $images = $extractor->extractGalleryImages($html, $listing->website, $og['image'] ?? null, self::MAX_IMAGES);

        if (empty($og) && empty($images)) {
            $this->markScraped($listing);

            return;
        }

        $fill = $this->buildFillableUpdates($extractor, $listing, $og, $images);

        $fill['scrape_data'] = array_merge($listing->scrape_data ?? [], [
            'og' => $og,
            'crawled_at' => now()->toIso8601String(),
        ]);

        $listing->update($fill);
        $this->markScraped($listing);

        if ($listing->partner) {
            $listing->partner->fill(array_filter([
                'email' => $listing->partner->email ? null : ($og['email'] ?? null),
                'phone' => $listing->partner->phone ? null : ($og['phone'] ?? null),
            ]))->save();
        }
    }

    /** @param array<string, string> $og
     *  @param list<string> $images
     *  @return array<string, mixed> */
    private function buildFillableUpdates(WebsiteContentExtractor $extractor, Listing $listing, array $og, array $images): array
    {
        $fill = [];

        // A listing showing directory photos is showing photos we may not
        // publish at all. The owner's own site outranks that — but it is still
        // their copyright, so the upgrade is staged for approval rather than
        // swapped in live. Only a listing with no photo at all gets one
        // published outright, which is this job's long-standing behaviour.
        $liveIsUpgradable = $listing->image
            && ContentSource::WebsiteScrape->outranks($listing->photos_source);

        if (! empty($images) && (! $listing->image || $liveIsUpgradable)) {
            $hero = $extractor->downloadPhoto(array_shift($images), $listing->slug);
            if ($hero) {
                $fill[$liveIsUpgradable ? 'pending_image' : 'image'] = $hero;
                $fill[$liveIsUpgradable ? 'pending_photos_source' : 'photos_source'] = ContentSource::WebsiteScrape;
            }
        }

        if (! empty($images) && (empty($listing->gallery) || $liveIsUpgradable)) {
            $gallery = [];
            foreach (array_slice($images, 0, self::MAX_IMAGES - 1) as $url) {
                $stored = $extractor->downloadPhoto($url, $listing->slug);
                if ($stored) {
                    $gallery[] = $stored;
                }
            }
            if ($gallery) {
                $fill[$liveIsUpgradable ? 'pending_gallery' : 'gallery'] = $gallery;
            }
        }

        // Directory prose specifically — not "anything this outranks".
        //
        // og:description is a meta tag: often truncated at 160 characters,
        // SEO-stuffed, or a bare "Welcome to our website". It beats a third
        // party's description of the property, so it replaces directory text.
        // It does not beat copy we wrote, which was generated from the
        // listing's facts with this same site as context — letting a later
        // re-crawl swap that for the meta blurb would be a downgrade dressed
        // up as an upgrade.
        $replacesDirectoryText = $listing->description_source === ContentSource::Directory;

        if (! empty($og['description'])
            && (blank($listing->getTranslation('description', 'en')) || $replacesDirectoryText)) {
            $fill['description'] = ['en' => $og['description']];
            $fill['description_source'] = ContentSource::WebsiteScrape;
        }

        if (! empty($og['email']) && ! $listing->contact_email) {
            $fill['contact_email'] = $og['email'];
        }

        if (! empty($og['phone']) && ! $listing->phone) {
            $fill['phone'] = $og['phone'];
        }

        return $fill;
    }

    private function markScraped(Listing $listing): void
    {
        $listing->update(['og_scraped_at' => now()]);
    }
}
