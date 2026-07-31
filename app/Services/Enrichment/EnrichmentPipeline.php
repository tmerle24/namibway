<?php

namespace App\Services\Enrichment;

use App\Models\Listing;

/**
 * Orchestrates the full VisitNamibia enrichment run for one listing:
 * normalize -> find website -> fetch homepage -> AI structured extraction ->
 * AI description generation -> import photos -> recalculate completion
 * score. Every write is additive/fill-gaps-only (never overwrites a value
 * already on the listing), matching the convention already established by
 * CrawlListingWebsiteJob/FetchGooglePlacesPhotoJob.
 */
class EnrichmentPipeline
{
    public function __construct(
        private readonly WebsiteFinderService $websiteFinder,
        private readonly WebsiteContentExtractor $extractor,
        private readonly AIListingExtractorService $aiExtractor,
        private readonly AIDescriptionGeneratorService $descriptionGenerator,
        private readonly CompletionScoreService $scoreService,
    ) {}

    /**
     * @param  list<string>|null  $steps  Restrict to a subset of ['website', 'ai_extract', 'images', 'description'].
     *                                    Null runs the full pipeline.
     * @return array{fields_updated: list<string>, tokens_used: int, cost_estimate: float, log: list<string>, score: int}
     */
    public function run(Listing $listing, ?array $steps = null): array
    {
        $log = [];
        $tokensUsed = 0;
        $costEstimate = 0.0;
        $aiGeneratedFields = [];
        $updates = [];
        $pageText = null;

        $this->normalize($listing);

        $runs = fn (string $step): bool => $steps === null || in_array($step, $steps, true);

        if ($runs('website') && blank($listing->website)) {
            $this->findWebsite($listing, $updates, $log);
        }

        $website = $updates['website'] ?? $listing->website;
        $html = null;

        if ($runs('ai_extract') && filled($website)) {
            $html = $this->fetchHomepage($website, $log);

            if ($html !== null) {
                $pageText = $this->htmlToText($html);
                [$aiTokens, $aiCost] = $this->extractStructuredData($listing, $pageText, $updates, $aiGeneratedFields, $log);
                $tokensUsed += $aiTokens;
                $costEstimate += $aiCost;
            }
        }

        if ($runs('images') && $html !== null) {
            $this->fillImages($listing, $html, $website, $updates, $log);
        }

        if ($runs('description') && $this->needsDescription($listing)) {
            [$descTokens, $descCost] = $this->generateDescription($listing, $pageText, $updates, $aiGeneratedFields, $log);
            $tokensUsed += $descTokens;
            $costEstimate += $descCost;
        }

        $updates['last_enriched_at'] = now();
        $listing->forceFill($updates)->saveQuietly();

        $score = $this->scoreService->recalculate($listing, $aiGeneratedFields);

        return [
            'fields_updated' => array_keys($updates),
            'tokens_used' => $tokensUsed,
            'cost_estimate' => round($costEstimate, 4),
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
    private function findWebsite(Listing $listing, array &$updates, array &$log): void
    {
        $found = $this->websiteFinder->find($listing);

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

    /** @param array<string, mixed> $updates
     *  @param list<string> $log */
    private function fillImages(Listing $listing, string $html, string $baseUrl, array &$updates, array &$log): void
    {
        if ($listing->image && ! empty($listing->gallery)) {
            return;
        }

        $images = $this->extractor->extractGalleryImages($html, $baseUrl, null);

        if (empty($images)) {
            return;
        }

        if (! $listing->image) {
            $hero = $this->extractor->downloadPhoto(array_shift($images), $listing->slug, 'enrichment');

            if ($hero) {
                $updates['image'] = $hero;
                $log[] = 'Hero image imported from website.';
            }
        }

        if (empty($listing->gallery) && ! empty($images)) {
            $gallery = [];

            foreach (array_slice($images, 0, 5) as $url) {
                $stored = $this->extractor->downloadPhoto($url, $listing->slug, 'enrichment');

                if ($stored) {
                    $gallery[] = $stored;
                }
            }

            if ($gallery !== []) {
                $updates['gallery'] = $gallery;
                $log[] = count($gallery).' gallery image(s) imported from website.';
            }
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
