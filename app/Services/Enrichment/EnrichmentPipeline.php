<?php

namespace App\Services\Enrichment;

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
 */
class EnrichmentPipeline
{
    public function __construct(
        private readonly WebsiteFinderService $websiteFinder,
        private readonly WebsiteContentExtractor $extractor,
        private readonly AIListingExtractorService $aiExtractor,
        private readonly AIDescriptionGeneratorService $descriptionGenerator,
        private readonly GooglePlacesPhotoFinder $photoFinder,
        private readonly OsmLocationFinder $osmFinder,
        private readonly CompletionScoreService $scoreService,
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
            [$aiTokens, $aiCost] = $this->extractStructuredData($listing, $pageText, $updates, $aiGeneratedFields, $log);
            $tokensUsed += $aiTokens;
            $costEstimate += $aiCost;
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
            [$descTokens, $descCost] = $this->generateDescription($listing, $pageText, $updates, $aiGeneratedFields, $log);
            $tokensUsed += $descTokens;
            $costEstimate += $descCost;
        }

        $fieldsChanged = array_keys($updates);

        if ($fieldsChanged !== []) {
            // Reflects what actually happened, not just what was requested — e.g. a full
            // run that never found a website never calls Claude either, and should read
            // as such in the Log tab rather than claiming AI involvement it didn't have.
            $updates['enriched_by'] = $tokensUsed > 0 ? 'AI' : 'Automated (no AI)';
        }

        $updates['last_enriched_at'] = now();
        $listing->forceFill($updates)->saveQuietly();

        $score = $this->scoreService->recalculate($listing, $aiGeneratedFields);

        $placesCalls = PlacesCostEstimator::mergeCallCounts($this->websiteFinder->callCounts(), $this->photoFinder->callCounts());
        $placesCost = PlacesCostEstimator::estimateCost($placesCalls);

        return [
            'fields_updated' => $fieldsChanged,
            'tokens_used' => $tokensUsed,
            'cost_estimate' => round($costEstimate, 4),
            'places_calls' => $placesCalls,
            'places_cost_estimate' => round($placesCost, 4),
            'log' => $log,
            'score' => $score,
        ];
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
        $found = $this->websiteFinder->find($listing, $useGooglePlaces);

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

        $placesFacts = $this->websiteFinder->findLocationFacts($listing);

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

        if (! empty($og['description']) && blank($listing->getTranslation('description', 'en', useFallbackLocale: false)) && ! array_key_exists('description', $updates)) {
            $updates['description'] = ['en' => $og['description']];
            $log[] = 'Description imported from website (og:description).';
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
        $result = $this->photoFinder->findPhotoUrls($listing);
        $urls = $result['urls'];

        if ($urls === []) {
            $log[] = 'No Google Places photos found.';

            return;
        }

        $updates['image'] = $urls[0];

        if (empty($listing->gallery) && count($urls) > 1) {
            $updates['gallery'] = array_slice($urls, 1);
        }

        $updates['photos_source'] = 'google_places';
        $updates['photos_attribution'] = $result['attribution'];
        $updates['google_photos_expire_at'] = now()->addDays(30);

        $log[] = count($urls).' photo(s) imported from Google Places (cached until '.$updates['google_photos_expire_at']->toDateString().').';
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

        if ($listing->latitude === null && ! empty($data['latitude']) && ! empty($data['longitude'])) {
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

    private function needsDescription(Listing $listing): bool
    {
        return blank($listing->getTranslation('description', 'en', useFallbackLocale: false))
            || blank($listing->getTranslation('short_description', 'en', useFallbackLocale: false));
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

        if (blank($listing->getTranslation('description', 'en', useFallbackLocale: false)) && filled($data['long_description'])) {
            $updates['description'] = ['en' => $data['long_description']];
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
