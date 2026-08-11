<?php

namespace App\Sites\Rendering;

use App\Models\Site;
use App\Services\Booking\RoomOffers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the booking block shows, read live from the booking system.
 *
 * ## Nothing is stored and nothing is cached
 *
 * Availability and prices come from the same calendar the lodge sells from,
 * every render. A price copied into a website is a price that can be wrong, and
 * a wrong price under a "book" button is the one kind of stale content that
 * costs somebody money.
 *
 * The read is a direct service call rather than an HTTP request to our own
 * public API — /api/v1 is token-authenticated for partner integrations, has no
 * booking endpoint, and answers availability out of the partner's connector
 * instead of our calendar. It would be the wrong answer, fetched the expensive
 * way, with a credential on a public page.
 *
 * ## Why a plain GET form
 *
 * The dates arrive in the query string and the page re-renders. No JavaScript,
 * no session, no CSRF token, nothing to hydrate — which is what lets the whole
 * block cost a few hundred bytes and work on a phone with scripting off. It is
 * also the honest shape for slice 1: the block quotes, and hands over to the
 * platform to book.
 */
class BookingPanel
{
    public function __construct(private readonly RoomOffers $offers) {}

    /**
     * Null where this site has nothing sellable behind it — a restaurant, a
     * workshop, a lodge whose inventory sits on somebody else's PMS. The
     * renderer puts the contact block in its place rather than showing a
     * booking form that cannot answer.
     */
    public function for(Site $site, Request $request): ?BookingPanelData
    {
        $listing = $site->sourceListing;

        if ($listing === null
            || $listing->partner?->booking_enabled !== true
            || ! $listing->bookableUnits()->where('is_active', true)->exists()) {
            return null;
        }

        [$checkIn, $checkOut] = $this->dates($request);
        $adults = max(1, min(20, (int) $request->query('adults', '2')));
        $children = max(0, min(20, (int) $request->query('children', '0')));

        if ($checkIn === null || $checkOut === null) {
            return new BookingPanelData($listing, null, null, $adults, $children, []);
        }

        try {
            $offers = $this->offers->for($listing, $checkIn, $checkOut, $adults, $children);
        } catch (Throwable) {
            // A pricing or calendar failure must not take the whole website
            // down — the rest of the page is the customer's shopfront and is
            // still correct. The block falls back to its empty state, which
            // reads as "no rooms for those dates" rather than as an error.
            $offers = [];
        }

        return new BookingPanelData($listing, $checkIn, $checkOut, $adults, $children, $offers);
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function dates(Request $request): array
    {
        $in = $request->query('check_in');
        $out = $request->query('check_out');

        if (! is_string($in) || ! is_string($out) || $in === '' || $out === '') {
            return [null, null];
        }

        try {
            $checkIn = CarbonImmutable::parse($in)->startOfDay();
            $checkOut = CarbonImmutable::parse($out)->startOfDay();
        } catch (Throwable) {
            return [null, null];
        }

        // A stay in the past is a typo, and quoting one would be answering a
        // question nobody asked. Half-open intervals throughout, so same-day
        // out is not a stay at all.
        if ($checkOut <= $checkIn || $checkIn->lt(CarbonImmutable::today())) {
            return [null, null];
        }

        return [$checkIn, $checkOut];
    }
}
