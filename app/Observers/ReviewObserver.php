<?php

namespace App\Observers;

use App\Models\Listing;
use App\Models\Review;

class ReviewObserver
{
    /**
     * Keep the listing's aggregate rating in sync with its approved reviews.
     * A listing with no approved reviews yet keeps whatever manually-entered
     * rating it already has (e.g. imported from Google/TripAdvisor via Filament).
     */
    public function saved(Review $review): void
    {
        $this->recompute($review->listing_id);
    }

    public function deleted(Review $review): void
    {
        $this->recompute($review->listing_id);
    }

    private function recompute(int $listingId): void
    {
        $approved = Review::where('listing_id', $listingId)->where('is_approved', true);
        $count = $approved->count();

        if ($count === 0) {
            return;
        }

        Listing::whereKey($listingId)->update([
            'rating' => round((float) $approved->avg('rating'), 1),
            'rating_count' => $count,
        ]);
    }
}
