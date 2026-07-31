<?php

namespace App\Services\Enrichment;

use App\Models\Listing;

/**
 * Calculates a listing's 0-100 data-completion score and keeps the
 * per-field listing_field_status rows in sync, per the weighting in the
 * VisitNamibia enrichment spec: website 10, email 10, phone 10, address 10,
 * gps 10, description 20, photos 20, facilities 5, activities 5.
 */
class CompletionScoreService
{
    /** @var array<string, int> */
    private const WEIGHTS = [
        'website' => 10,
        'email' => 10,
        'phone' => 10,
        'address' => 10,
        'gps' => 10,
        'description' => 20,
        'photos' => 20,
        'facilities' => 5,
        'activities' => 5,
    ];

    /**
     * Recomputes the score, persists it (and enrichment_status) on the
     * listing, and upserts one listing_field_status row per tracked field.
     *
     * @param  list<string>  $aiGeneratedFields  Field keys (matching WEIGHTS) that this
     *                                           enrichment run just wrote, so their status
     *                                           reads "ai_generated" rather than "complete"
     *                                           until a human/owner verifies the listing.
     */
    public function recalculate(Listing $listing, array $aiGeneratedFields = []): int
    {
        $presence = $this->presence($listing);

        $score = 0;

        foreach ($presence as $field => $isPresent) {
            if ($isPresent) {
                $score += self::WEIGHTS[$field];
            }

            $listing->fieldStatuses()->updateOrCreate(
                ['field' => $field],
                ['status' => $this->statusFor($listing, $field, $isPresent, $aiGeneratedFields)],
            );
        }

        $listing->forceFill([
            'enrichment_score' => $score,
            'enrichment_status' => $this->statusLabel($score),
        ])->saveQuietly();

        return $score;
    }

    /** @return array<string, bool> */
    private function presence(Listing $listing): array
    {
        return [
            'website' => filled($listing->website),
            'email' => filled($listing->contact_email),
            'phone' => filled($listing->phone),
            'address' => filled($listing->address),
            'gps' => $listing->latitude !== null && $listing->longitude !== null,
            'description' => filled($listing->getTranslation('description', 'en', useFallbackLocale: false)),
            'photos' => filled($listing->image),
            'facilities' => ! empty($listing->facilities),
            'activities' => ! empty($listing->activities),
        ];
    }

    /** @param  list<string>  $aiGeneratedFields */
    private function statusFor(Listing $listing, string $field, bool $isPresent, array $aiGeneratedFields): string
    {
        if (! $isPresent) {
            return 'missing';
        }

        if ($listing->verified_at !== null) {
            return 'verified';
        }

        return in_array($field, $aiGeneratedFields, true) ? 'ai_generated' : 'complete';
    }

    private function statusLabel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'complete',
            $score >= 60 => 'partial',
            default => 'incomplete',
        };
    }
}
