<?php

namespace App\Services\Enrichment;

use App\Models\Listing;
use Illuminate\Support\Facades\Http;

/**
 * Step 2 of the enrichment pipeline: find a listing's website, following the
 * priority chain from the spec:
 *
 *   A) Listing already has a contact email -> extract its domain and probe
 *      https(s)://(www.)domain directly. Confidence 100.
 *   B) No email -> look the listing up on Google Places by name + region (Place
 *      Details also hands back phone/address/GPS for free, extracted separately
 *      by findLocationFacts()). Confidence 90.
 *
 * There is no general web-search API configured for this project (no SerpAPI/
 * Bing key) — Google Places doubles as the "search by name" step, since NTB
 * listings are almost always real, mappable businesses.
 *
 * All Places lookups go through GooglePlacesLookupService, shared with
 * GooglePlacesPhotoFinder within one enrichment run — see that class for why.
 *
 * find()/findLocationFacts() also skip the Places call entirely once
 * Listing::google_places_checked_at is within enrichment.refresh_days — a business
 * Google genuinely has no data for would otherwise get re-queried on every single
 * future enrichment run indefinitely, since a blank website/incomplete location
 * facts never stops looking incomplete. (Deliberately separate from
 * google_photos_checked_at, which only governs the standalone photo job's own
 * 30-day Places-photo caching window — unrelated concern, don't conflate them.)
 */
class WebsiteFinderService
{
    /**
     * @return array{website: string, confidence: int, source: string, phone?: string, address?: string, latitude?: float, longitude?: float}|null
     */
    public function find(Listing $listing, bool $useGooglePlaces, GooglePlacesLookupService $lookup): ?array
    {
        $found = $this->fromEmailDomain($listing);

        if ($found !== null || ! $useGooglePlaces || $this->recentlyCheckedPlaces($listing)) {
            return $found;
        }

        $details = $lookup->lookup($listing);

        if ($details === null || blank($details['website'])) {
            return null;
        }

        $found = [
            'website' => $details['website'],
            'confidence' => 90,
            'source' => 'google_places',
        ];

        if (filled($details['formatted_phone_number'])) {
            $found['phone'] = $details['formatted_phone_number'];
        }

        if (filled($details['formatted_address'])) {
            $found['address'] = $details['formatted_address'];
        }

        if ($details['lat'] !== null && $details['lng'] !== null) {
            $found['latitude'] = $details['lat'];
            $found['longitude'] = $details['lng'];
        }

        return $found;
    }

    /** @return array{website: string, confidence: int, source: string}|null */
    private function fromEmailDomain(Listing $listing): ?array
    {
        if (blank($listing->contact_email) || ! str_contains($listing->contact_email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(explode('@', $listing->contact_email)[1] ?? ''));

        if ($domain === '' || $this->isFreemailDomain($domain)) {
            return null;
        }

        foreach (["https://{$domain}", "https://www.{$domain}"] as $candidate) {
            if ($this->respondsOk($candidate)) {
                return ['website' => $candidate, 'confidence' => 100, 'source' => 'email_domain'];
            }
        }

        return null;
    }

    /**
     * Independent of find(): that requires a website in the Place Details response to
     * return anything at all, discarding a perfectly good geocode whenever a business
     * simply doesn't have a website on file with Google. This is for the pipeline's
     * GPS/address/phone fill-in step, which has no such requirement — same underlying
     * lookup (memoized by GooglePlacesLookupService), just reading different fields
     * off it.
     *
     * @return array{phone?: string, address?: string, latitude?: float, longitude?: float}
     */
    public function findLocationFacts(Listing $listing, GooglePlacesLookupService $lookup): array
    {
        if ($this->recentlyCheckedPlaces($listing)) {
            return [];
        }

        $details = $lookup->lookup($listing);

        if ($details === null) {
            return [];
        }

        $facts = [];

        if (filled($details['formatted_phone_number'])) {
            $facts['phone'] = $details['formatted_phone_number'];
        }

        if (filled($details['formatted_address'])) {
            $facts['address'] = $details['formatted_address'];
        }

        if ($details['lat'] !== null && $details['lng'] !== null) {
            $facts['latitude'] = $details['lat'];
            $facts['longitude'] = $details['lng'];
        }

        return $facts;
    }

    private function recentlyCheckedPlaces(Listing $listing): bool
    {
        return $listing->google_places_checked_at !== null
            && $listing->google_places_checked_at->gt(now()->subDays((int) config('enrichment.refresh_days')));
    }

    private function respondsOk(string $url): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; NamibWay/1.0; +https://namibway.com)'])
                ->get($url);
        } catch (\Throwable) {
            return false;
        }

        return $response->successful();
    }

    private function isFreemailDomain(string $domain): bool
    {
        return in_array($domain, ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'aol.com', 'live.com', 'gmx.net', 'gmx.de', 'web.de'], true);
    }
}
