<?php

namespace App\Sites\Rendering;

use App\Models\Listing;
use App\Services\Booking\RoomOffer;
use Carbon\CarbonImmutable;

/**
 * The booking block's state for one render: the dates asked about, and what the
 * calendar said. Never persisted — see BookingPanel.
 */
final class BookingPanelData
{
    /**
     * @param  array<int, RoomOffer>  $offers
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly ?CarbonImmutable $checkIn,
        public readonly ?CarbonImmutable $checkOut,
        public readonly int $adults,
        public readonly int $children,
        public readonly array $offers,
    ) {}

    /** Whether somebody has asked about specific dates yet. */
    public function hasQuery(): bool
    {
        return $this->checkIn !== null && $this->checkOut !== null;
    }

    public function nights(): int
    {
        // Both checked inline rather than through hasQuery(), so the nulls are
        // narrowed where a reader — and a static analyser — can see it.
        if ($this->checkIn === null || $this->checkOut === null) {
            return 0;
        }

        return max(1, (int) $this->checkIn->diffInDays($this->checkOut));
    }

    /**
     * Where "book" goes.
     *
     * The platform's listing page, with the dates carried across — booking
     * itself runs through namibway.com, where the account and the
     * one-active-request gate live (App\Services\Booking\ActiveRequestGate).
     * Whether a customer's own site should instead take a booking straight to
     * that one property, outside that gate, is a real decision and it is not
     * this slice's to make: the gate exists to stop one traveller spamming many
     * partners, which a guest booking the lodge whose site they are reading is
     * not.
     */
    public function bookingUrl(): string
    {
        $query = array_filter([
            'check_in' => $this->checkIn?->toDateString(),
            'check_out' => $this->checkOut?->toDateString(),
            'adults' => (string) $this->adults,
            'children' => $this->children > 0 ? (string) $this->children : null,
        ]);

        return url('/listings/'.$this->listing->slug).($query === [] ? '' : '?'.http_build_query($query));
    }
}
