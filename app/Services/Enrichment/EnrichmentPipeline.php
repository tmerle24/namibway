<?php

namespace App\Services\Enrichment;

use App\Enums\ContentSource;
use App\Models\Listing;

/**
 * Orchestrates the full VisitNamibia enrichment run for one listing:
 * normalize -> find website -> location facts (GPS/phone/address, OpenStreetMap
 * first since it's free, Google Places only for whatever OSM didn't cover) ->
 * fetch homepage -> non-AI OG-tag/contact scrape and/or AI structured extraction
 * -> import photos (website scrape, falling back to Google Places Photos) -> AI
 * description generation -> recalculate completion score. Every write is
 * additive/fill-gaps-only (never overwrites a value already on the listing),
 * matching the convention already established by
 * CrawlListingWebsiteJob/FetchGooglePlacesPhotoJob.
 *
 * The 'scrape' step (and 'website', 'images') never call Claude — only
 * 'ai_extract' and 'description' do. A caller that only requests the
 * former gets a genuinely AI-free, zero-token enrichment pass — OSM/Places
 * location lookups and website/Places photo scraping still run, but neither
 * is billed by Anthropic (Places itself has its own separate, non-AI cost).
 *
 * All three Places-backed steps (findWebsite, fillLocationFacts,
 * fillImagesFromGooglePlaces) share one GooglePlacesLookupService instance for
 * the run — the same business only ever gets looked up on Places once, not
 * separately by each step.
 *
 * Both AI steps and every Places-backed step also check EnrichmentBudgetGuard
 * before spending anything — once a category's daily cap is hit, the affected
 * step is skipped (logged, not thrown) rather than the run failing outright, so
 * whatever else the run already gathered still gets saved.
 *
 * A step throwing mid-run (e.g. Claude erroring) is caught, and whatever was
 * already found/spent is still saved and reported — see
 * EnrichmentPartialFailureException, which carries that partial result back to
 * the caller instead of it just vanishing into a swallowed exception the way it
 * used to.
 */
class EnrichmentPipeline
{
    public function __construct(
        private readonly WebsiteFinderService $websiteFinder,
        private readonly WebsiteContentExtractor $extractor,
        private readonly AIListingExtractorService $aiExtractor,
        private readonly AIDescriptionGeneratorService $descriptionGenerator,
        private readonly GooglePlacesPhotoFinder $photoFinder,
        private readonly GooglePlacesLookupService $placesLookup,
        private readonly OsmLocationFinder $osmFinder,
        private readonly CompletionScoreService $scoreService,
        private readonly EnrichmentBudgetGuard $budgetGuard,
    ) {}

    /**
     * @param  list<string>|null  $steps  Restrict to a subset of ['website', 'scrape', 'ai_extract', 'images', 'description'].
     *                                    Null runs the full pipeline.
     * @param  bool  $useGooglePlaces  Defaults to true for automated/nightly/bulk callers. The
     *                                 "Enrich" dashboard action lets an admin opt out per run — Places
     *                                 is a paid API, unlike the OSM lookup this otherwise falls back to.
     * @return array{fields_updated: list<string>, tokens_used: int, cost_estimate: float, places_calls: array<string, int>, places_cost_estimate: float, log: list<string>, score: int}
     */
    public function run(Listing $listing, ?array $steps = null, bool $useGooglePlaces = true): array
    {
        $log = [];
        $tokensUsed = 0;
        $costEstimate = 0.0;
        $aiGeneratedFields = [];
        $updates = [];
        $pageText = null;

        $this->normalize($listing);

        $runs = fn (string $step): bool => $steps === null || in_array($step, $steps, true);

        // Wrapped so a step failing partway through (e.g. Claude erroring mid-run) still
        // saves whatever was already found and still records whatever was already spent
        // — see EnrichmentPartialFailureException. $caught is rethrown, wrapped with the
        // partial result, only after that's done — the alternative (letting it propagate
        // immediately) is what used to silently discard both.
        $caught = null;

        try {
            if ($runs('website')) {
                if (blank($listing->website)) {
                    $this->findWebsite($listing, $updates, $log, $useGooglePlaces);
                }

                // Independent of whether a website was found above — OSM/Google Places are
                // authoritative geocoding sources, and findWebsite() only carries GPS/phone/
                // address along when the same Place happens to also have a website on file.
                // Checked against $updates too, so this doesn't redundantly re-query them
                // when findWebsite() already got everything from the very same lookup.
                if (! $this->hasAllLocationFacts($listing, $updates)) {
                    $this->fillLocationFacts($listing, $updates, $log, $useGooglePlaces);
                }
            }

            $website = $updates['website'] ?? $listing->website;
            $html = null;
            $ogImage = null;

            // Fetched once and shared: 'scrape' (non-AI OG-tag/contact fill) and 'ai_extract'
            // (Claude structured extraction) both work from the same homepage fetch.
            if (($runs('scrape') || $runs('ai_extract')) && filled($website)) {
                $html = $this->fetchHomepage($website, $log);

                if ($html !== null) {
                    $pageText = $this->htmlToText($html);
                }
            }

            if ($runs('scrape') && $html !== null) {
                $ogImage = $this->fillFromOgTags($listing, $html, $website, $updates, $log);
            }

            if ($runs('ai_extract') && $html !== null) {
                if ($this->recentlyExtracted($listing)) {
                    $log[] = 'Structured extraction skipped — already attempted recently.';
                } elseif ($this->budgetGuard->hasBudget('ai')) {
                    [$aiTokens, $aiCost] = $this->extractStructuredData($listing, $pageText, $updates, $aiGeneratedFields, $log);
                    $tokensUsed += $aiTokens;
                    $costEstimate += $aiCost;
                    $updates['ai_extracted_at'] = now();
                } else {
                    $log[] = 'Daily AI budget exhausted — skipped structured extraction.';
                }
            }

            if ($runs('images') || $runs('scrape')) {
                if ($html !== null) {
                    $this->fillImages($listing, $html, $website, $ogImage, $updates, $log);
                }

                // No live image yet — either nothing was scraped, or fillImages() only staged
                // pending website photos awaiting owner/admin approval. Google Places can
                // publish immediately (no consent issue there), so it fills the gap live in
                // the meantime; same lookup FetchGooglePlacesPhotoJob does on its own schedule.
                if ($useGooglePlaces && ! $listing->image && ! array_key_exists('image', $updates)) {
                    $this->fillImagesFromGooglePlaces($listing, $updates, $log);
                }
            }

            if ($runs('description') && $this->needsDescription($listing)) {
                if ($this->budgetGuard->hasBudget('ai')) {
                    [$descTokens, $descCost] = $this->generateDescription($listing, $pageText, $updates, $aiGeneratedFields, $log);
                    $tokensUsed += $descTokens;
                    $costEstimate += $descCost;
                } else {
                    $log[] = 'Daily AI budget exhausted — skipped description generation.';
                }
            }
        } catch (\Throwable $e) {
            $log[] = 'Run interrupted: '.$e->getMessage();
            $caught = $e;
        }

        $fieldsChanged = array_keys($updates);

        if ($fieldsChanged !== []) {
            // Reflects what actually happened, not just what was requested — e.g. a full
            // run that never found a website never calls Claude either, and should read
            // as such in the Log tab rather than claiming AI involvement it didn't have.
            $updates['enriched_by'] = $tokensUsed > 0 ? 'AI' : 'Automated (no AI)';
        }

        if ($this->placesLookup->wasAttempted($listing->id)) {
            $updates['google_places_checked_at'] = now();
        }

        $updates['last_enriched_at'] = now();
        $listing->forceFill($updates)->saveQuietly();

        $score = $this->scoreService->recalculate($listing, $aiGeneratedFields);

        $placesCalls = PlacesCostEstimator::mergeCallCounts($this->placesLookup->callCounts(), $this->photoFinder->callCounts());
        $placesCost = PlacesCostEstimator::estimateCost($placesCalls);

        $result = [
            'fields_updated' => $fieldsChanged,
            'tokens_used' => $tokensUsed,
            'cost_estimate' => round($costEstimate, 4),
            'places_calls' => $placesCalls,
            'places_cost_estimate' => round($placesCost, 4),
            'log' => $log,
            'score' => $score,
        ];

        if ($caught !== null) {
            throw new EnrichmentPartialFailureException($caught->getMessage(), $result, $caught);
        }

        return $result;
    }

    private function normalize(Listing $listing): void
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $listing->name));

        if ($name !== '' && $name !== $listing->name) {
            $listing->name = $name;
        }

        if ($listing->address) {
            $address = trim((string) preg_replace('/\s+/', ' ', $listing->address));

            if ($address !== $listing->address) {
                $listing->address = $address;
            }
        }

        if ($listing->isDirty()) {
            $listing->saveQuietly();
        }
    }

    /** @param array<string, mixed> $updates
     *  @param list<string> $log */
    private function findWebsite(Listing $listing, array &$updates, array &$log, bool $useGooglePlaces): void
    {
        $found = $this->websiteFinder->find($listing, $useGooglePlaces, $this->placesLookup);

        if ($found === null) {
            $log[] = 'No website found.';

            return;
        }

        $updates['website'] = $found['website'];
        $updates['data_source'] = $found['source'];
        $updates['confidence'] = $found['confidence'];

        if (blank($listing->phone) && ! empty($found['phone'])) {
            $updates['phone'] = $found['phone'];
        }

        if (blank($listing->address) && ! empty($found['address'])) {
            $updates['address'] = $found['address'];
        }

        if ($listing->latitude === null && ! empty($found['latitude']) && ! empty($found['longitude'])) {
            $updates['latitude'] = $found['latitude'];
            $updates['longitude'] = $found['longitude'];
        }

        $log[] = "Website found via {$found['source']} (confidence {$found['confidence']}%): {$found['website']}";
    }

    /**
     * Tries OpenStreetMap (free, no API key) first, then only falls back to the paid
     * Google Places lookup for whatever OSM didn't cover — OSM's Nominatim has no cost
     * per call, so trying it first cuts down how often the Places API gets billed.
     *
     * @param array<string, mixed> $updates
     * @param list<string> $log
     */
    private function fillLocationFacts(Listing $listing, array &$updates, array &$log, bool $useGooglePlaces): void
    {
        $osmFacts = $this->osmFinder->findLocationFacts($listing);

        if ($osmFacts !== []) {
            $this->applyLocationFacts($listing, $osmFacts, $updates);
            $log[] = 'OpenStreetMap location facts (free): '.implode(', ', array_keys($osmFacts));
        }

        if ($this->hasAllLocationFacts($listing, $updates)) {
            return;
        }

        if (! $useGooglePlaces) {
            if ($osmFacts === []) {
                $log[] = 'No OpenStreetMap match — Google Places not requested for this run.';
            }

            return;
        }

        $placesFacts = $this->websiteFinder->findLocationFacts($listing, $this->placesLookup);

        if ($placesFacts === []) {
            if ($osmFacts === []) {
                $log[] = 'No location match on OpenStreetMap or Google Places.';
            }

            return;
        }

        $this->applyLocationFacts($listing, $placesFacts, $updates);
        $log[] = 'Google Places location facts: '.implode(', ', array_keys($placesFacts));
    }

    /** @param array<string, mixed> $updates */
    private function hasAllLocationFacts(Listing $listing, array $updates): bool
    {
        $hasGps = $listing->latitude !== null || array_key_exists('latitude', $updates);
        $hasPhone = filled($listing->phone) || array_key_exists('phone', $updates);
        $hasAddress = filled($listing->address) || array_key_exists('address', $updates);

        return $hasGps && $hasPhone && $hasAddress;
    }

    /**
     * @param array{phone?: string, address?: string, latitude?: float, longitude?: float} $facts
     * @param array<string, mixed> $updates
     */
    private function applyLocationFacts(Listing $listing, array $facts, array &$updates): void
    {
        if (blank($listing->phone) && ! array_key_exists('phone', $updates) && ! empty($facts['phone'])) {
            $updates['phone'] = $facts['phone'];
        }

        if (blank($listing->address) && ! array_key_exists('address', $updates) && ! empty($facts['address'])) {
            $updates['address'] = $facts['address'];
        }

        if ($listing->latitude === null && ! array_key_exists('latitude', $updates) && ! empty($facts['latitude']) && ! empty($facts['longitude'])) {
            $updates['latitude'] = $facts['latitude'];
            $updates['longitude'] = $facts['longitude'];
        }
    }

    /**
     * Non-AI homepage scrape: og:/meta description and mailto:/tel: contact info, the same
     * signals CrawlListingWebsiteJob already pulls on its own recurring schedule — inlined
     * here so a 'scrape' step gets an immediate result instead of waiting for that job's
     * next pass. Never AI-generated — copied straight from the site's own tags — so this
     * doesn't count towards $aiGeneratedFields.
     *
     * @param array<string, mixed> $updates
     * @param list<string> $log
     * @return string|null The og:image URL, if any, to seed fillImages()'s hero candidate.
     */
    private function fillFromOgTags(Listing $listing, string $html, string $baseUrl, array &$updates, array &$log): ?string
    {
        $og = $this->extractor->extractSignals($html, $baseUrl);

        $descriptionUpgradable = ContentSource::WebsiteScrape->outranks($listing->description_source);

        if (! empty($og['description'])
            && (blank($listing->getTranslation('description', 'en', useFallbackLocale: false)) || $descriptionUpgradable)
            && ! array_key_exists('description', $updates)) {
            $updates['description'] = ['en' => $og['description']];
            $updates['description_source'] = ContentSource::WebsiteScrape;
            $log[] = $descriptionUpgradable
                ? 'Description replaced with the website\'s own (og:description) — the listing was showing directory text.'
                : 'Description imported from website (og:description).';
        }

        if (! empty($og['email']) && blank($listing->contact_email) && ! array_key_exists('contact_email', $updates)) {
            $updates['contact_email'] = $og['email'];
        }

        if (! empty($og['phone']) && blank($listing->phone) && ! array_key_exists('phone', $updates)) {
            $updates['phone'] = $og['phone'];
        }

        return $og['image'] ?? null;
    }

    /**
     * Google Places photos don't need the owner's separate consent the way scraping
     * their own website's photos does — Places is a third-party directory, not the
     * owner's own copyrighted marketing material — but Google's Maps Platform terms
     * only permit temporarily caching them (with attribution). google_photos_expire_at
     * is what the scheduled sweep in FetchGooglePlacesPhotos uses to enforce that: past
     * it, the cached copy is cleared and re-fetched fresh rather than kept indefinitely.
     *
     * @param array<string, mixed> $updates
     * @param list<string> $log
     */
    private function fillImagesFromGooglePlaces(Listing $listing, array &$updates, array &$log): void
    {
        $result = $this->photoFinder->findPhotoUrls($listing, $this->placesLookup);
        $urls = $result['urls'];

        if ($urls === []) {
            $log[] = 'No Google Places photos found.';

            return;
        }

        $updates['image'] = $urls[0];

        if (empty($listing->gallery) && count($urls) > 1) {
            $updates['gallery'] = array_slice($urls, 1);
        }

        $updates['photos_source'] = ContentSource::GooglePlaces;
        $updates['photos_attribution'] = $result['attribution'];

        $log[] = count($urls).' photo(s) imported from Google Places.';
    }

    /** @param list<string> $log */
    private function fetchHomepage(string $url, array &$log): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        if (! $this->extractor->claimHostSlot($host)) {
            $log[] = 'Host rate-limited — homepage fetch skipped this run.';

            return null;
        }

        if (! $this->extractor->robotsAllow($url)) {
            $log[] = 'robots.txt disallows crawling this site.';

            return null;
        }

        $html = $this->extractor->fetchHomepage($url);

        if ($html === null) {
            $log[] = 'Homepage fetch failed.';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $updates
     * @param list<string> $aiGeneratedFields
     * @param list<string> $log
     * @return array{0: int, 1: float}
     */
    private function extractStructuredData(Listing $listing, string $pageText, array &$updates, array &$aiGeneratedFields, array &$log): array
    {
        $result = $this->aiExtractor->extract($listing, $pageText);
        $data = $result['data'];

        foreach (['facilities', 'activities', 'languages', 'opening_hours'] as $field) {
            if (! empty($data[$field]) && empty($listing->{$field})) {
                $updates[$field] = $data[$field];
                $aiGeneratedFields[] = $field;
            }
        }

        if (blank($listing->address) && ! empty($data['address'])) {
            $updates['address'] = $data['address'];
        }

        if ($listing->latitude === null && is_numeric($data['latitude'] ?? null) && is_numeric($data['longitude'] ?? null)) {
            $updates['latitude'] = $data['latitude'];
            $updates['longitude'] = $data['longitude'];
        }

        if (blank($listing->phone) && ! empty($data['phone'])) {
            $updates['phone'] = $data['phone'];
        }

        if (blank($listing->contact_email) && ! empty($data['email'])) {
            $updates['contact_email'] = $data['email'];
        }

        $log[] = 'AI structured extraction complete: '.implode(', ', array_keys($data) ?: ['nothing found']);

        return [$result['usage']['input_tokens'] + $result['usage']['output_tokens'], $this->estimateCost(config('enrichment.extraction_model'), $result['usage'])];
    }

    /**
     * Photos scraped off the listing's own website are the owner's copyrighted
     * marketing material — we have no license to publish them just because we could
     * download them. Staged into pending_image/pending_gallery instead of the live
     * image/gallery columns; only Listing::approvePendingPhotos() (triggered by the
     * owner via their claim-token preview link, or an admin on their behalf) makes
     * them public. See EnrichmentResource/ListingController for that approval flow.
     *
     * @param array<string, mixed> $updates
     * @param list<string> $log
     */
    private function fillImages(Listing $listing, string $html, string $baseUrl, ?string $heroHint, array &$updates, array &$log): void
    {
        if ($listing->image || filled($listing->pending_image)) {
            return;
        }

        $images = $this->extractor->extractGalleryImages($html, $baseUrl, $heroHint);

        if (empty($images)) {
            return;
        }

        $hero = $this->extractor->downloadPhoto(array_shift($images), $listing->slug, 'enrichment');

        if (! $hero) {
            return;
        }

        $updates['pending_image'] = $hero;
        $updates['pending_photos_source'] = ContentSource::WebsiteScrape;
        $log[] = 'Hero image imported from website — pending owner/admin approval before publishing.';

        if (empty($images)) {
            return;
        }

        $gallery = [];

        foreach (array_slice($images, 0, 5) as $url) {
            $stored = $this->extractor->downloadPhoto($url, $listing->slug, 'enrichment');

            if ($stored) {
                $gallery[] = $stored;
            }
        }

        if ($gallery !== []) {
            $updates['pending_gallery'] = $gallery;
            $log[] = count($gallery).' gallery image(s) imported from website — pending approval.';
        }
    }

    /**
     * Text that is missing — or that we hold but may not publish.
     *
     * The second case is why this is not just a blank check: a listing imported
     * from a directory looks "described" while carrying prose that can never go
     * live. Generating over it is the only way it becomes a real listing. Volume
     * is bounded by EnrichmentBudgetGuard's daily AI cap, same as every other
     * step here.
     */
    private function needsDescription(Listing $listing): bool
    {
        return blank($listing->getTranslation('description', 'en', useFallbackLocale: false))
            || blank($listing->getTranslation('short_description', 'en', useFallbackLocale: false))
            || ContentSource::AiGenerated->outranks($listing->description_source);
    }

    /**
     * Unlike needsDescription() — which self-resolves once description text exists and
     * never asks again — ai_extract has no such natural stop: a page can genuinely have
     * no facilities/activities/opening-hours to extract, and those fields would just
     * stay empty forever, keeping the listing's score low and getting it re-selected
     * (and Claude re-asked the same question) on every future enrichment run. This caps
     * that to once per enrichment.refresh_days.
     */
    private function recentlyExtracted(Listing $listing): bool
    {
        return $listing->ai_extracted_at !== null
            && $listing->ai_extracted_at->gt(now()->subDays((int) config('enrichment.refresh_days')));
    }

    /**
     * @param array<string, mixed> $updates
     * @param list<string> $aiGeneratedFields
     * @param list<string> $log
     * @return array{0: int, 1: float}
     */
    private function generateDescription(Listing $listing, ?string $pageText, array &$updates, array &$aiGeneratedFields, array &$log): array
    {
        $result = $this->descriptionGenerator->generate($listing, $pageText);
        $data = $result['data'];

        // Copy we wrote from the listing's own facts outranks a directory's
        // prose, so this replaces it rather than only filling a blank — that is
        // how a scraped listing stops showing someone else's text.
        $replacesDirectoryText = ContentSource::AiGenerated->outranks($listing->description_source);

        if ((blank($listing->getTranslation('description', 'en', useFallbackLocale: false)) || $replacesDirectoryText)
            && filled($data['long_description'])) {
            $updates['description'] = ['en' => $data['long_description']];
            $updates['description_source'] = ContentSource::AiGenerated;
            $aiGeneratedFields[] = 'description';
        }

        if (blank($listing->getTranslation('short_description', 'en', useFallbackLocale: false)) && filled($data['short_description'])) {
            $updates['short_description'] = ['en' => $data['short_description']];
        }

        if (blank($listing->seo_description) && filled($data['seo_description'])) {
            $updates['seo_description'] = $data['seo_description'];
        }

        if (blank($listing->meta_title) && filled($data['meta_title'])) {
            $updates['meta_title'] = $data['meta_title'];
        }

        $log[] = 'AI description copy generated.';

        return [$result['usage']['input_tokens'] + $result['usage']['output_tokens'], $this->estimateCost(config('enrichment.description_model'), $result['usage'])];
    }

    /** @param array{input_tokens: int, output_tokens: int} $usage */
    private function estimateCost(string $model, array $usage): float
    {
        $pricing = config("enrichment.pricing.{$model}");

        if (! $pricing) {
            return 0.0;
        }

        return ($usage['input_tokens'] / 1000) * $pricing['input'] + ($usage['output_tokens'] / 1000) * $pricing['output'];
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
