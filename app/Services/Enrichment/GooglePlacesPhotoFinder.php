<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Downloads real photos from a listing's Google Places Place Details — the place
 * lookup itself goes through GooglePlacesLookupService (shared with
 * WebsiteFinderService within one enrichment run, see that class); this class is
 * only responsible for the photo-reference -> downloaded-file step, which is
 * unique to photos and has no other caller to share with. Also checks
 * EnrichmentBudgetGuard before each individual photo download, not just once — a
 * single matched listing can pull up to 4 of these.
 */
class GooglePlacesPhotoFinder
{
    private const MAX_IMAGES = 4;

    /** @var array<string, int> Google Places calls made by this instance — see callCounts(). */
    private array $callCounts = ['photo' => 0];

    public function __construct(private readonly EnrichmentBudgetGuard $budgetGuard) {}

    /**
     * Google Places calls made since this instance was created, for cost-estimate
     * bookkeeping (EnrichmentPipeline/FetchGooglePlacesPhotoJob merge this with the
     * shared GooglePlacesLookupService's counts). A fresh instance is resolved per
     * queue job, so this only ever reflects one enrichment run.
     *
     * @return array<string, int>
     */
    public function callCounts(): array
    {
        return $this->callCounts;
    }

    /**
     * @return array{urls: list<string>, attribution: string|null} Public R2 URLs (hero
     *                                                              image first) plus the
     *                                                              combined html_attributions
     *                                                              Google's Places API requires
     *                                                              we credit alongside them.
     */
    public function findPhotoUrls(Listing $listing, GooglePlacesLookupService $lookup, int $max = self::MAX_IMAGES): array
    {
        $apiKey = config('services.google_places.key');

        if (blank($apiKey)) {
            return ['urls' => [], 'attribution' => null];
        }

        $details = $lookup->lookup($listing);
        $photos = $details['photos'] ?? [];

        if (empty($photos)) {
            return ['urls' => [], 'attribution' => null];
        }

        $urls = [];
        $attributions = [];

        foreach (array_slice($photos, 0, $max) as $photo) {
            if (! $this->budgetGuard->hasBudget('places')) {
                break;
            }

            $stored = $this->downloadPhoto($apiKey, $photo['photo_reference'], $listing->slug);

            if ($stored) {
                $urls[] = $stored;
                $attributions = [...$attributions, ...$photo['html_attributions']];
            }
        }

        return [
            'urls' => $urls,
            'attribution' => $attributions === [] ? null : implode(' · ', array_unique($attributions)),
        ];
    }

    private function downloadPhoto(string $apiKey, string $photoReference, string $slug): ?string
    {
        $this->callCounts['photo']++;

        try {
            $response = Http::timeout(20)->get('https://maps.googleapis.com/maps/api/place/photo', [
                'photoreference' => $photoReference,
                'maxwidth' => 1200,
                'key' => $apiKey,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');
            $ext = match (true) {
                str_contains((string) $contentType, 'png') => 'png',
                str_contains((string) $contentType, 'webp') => 'webp',
                default => 'jpg',
            };

            $filename = 'listings/google-places/'.$slug.'-'.Str::random(10).'.'.$ext;
            Storage::disk('r2')->put($filename, $response->body());

            return Storage::disk('r2')->url($filename);
        } catch (\Throwable) {
            return null;
        }
    }
}
