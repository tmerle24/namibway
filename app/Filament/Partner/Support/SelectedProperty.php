<?php

namespace App\Filament\Partner\Support;

use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Which property the partner panel is currently looking at.
 *
 * NWR is one Partner with roughly twenty camps, so "the partner's inventory"
 * is not one calendar. The choice is remembered on the account rather than in
 * the session — somebody who works one camp works it every morning, and a
 * session that expires overnight would put them back on whichever camp sorts
 * first. What is stored is **never trusted**: every read re-resolves the id
 * against the listings this user's partner actually owns, so a stale or
 * hand-edited id selects nothing rather than someone else's lodge.
 *
 * Only accommodation listings are properties. The lodge-facing screens read
 * room types, and an activity or a vehicle has none — offering them in the
 * switcher would promise a calendar that cannot exist.
 *
 * Nothing else in the panel depends on this. The existing Listing and Inquiry
 * resources scope themselves on partner_id and keep showing everything the
 * partner owns, selected property or not.
 *
 * Resolved once per request (bound scoped in AppServiceProvider), because the
 * topbar and the page both ask for it on every render.
 */
class SelectedProperty
{
    public const PREFERENCE_KEY = 'partner.selected_property_id';

    /** @var Collection<int, Listing>|null */
    private ?Collection $options = null;

    private bool $resolved = false;

    private ?Listing $current = null;

    /**
     * Every property the signed-in user may operate, in a stable order.
     *
     * @return Collection<int, Listing>
     */
    public function options(): Collection
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $partnerId = Auth::user()?->partner_id;

        if ($partnerId === null) {
            /** @var Collection<int, Listing> $empty */
            $empty = new Collection;

            return $this->options = $empty;
        }

        // Sorted in PHP, not by the database: `listings.name` is a translated
        // JSON column, so ORDER BY name asks Postgres to compare json values
        // and it refuses. A partner has tens of properties at most — NWR, the
        // largest case in sight, has about twenty camps.
        return $this->options = Listing::query()
            ->where('partner_id', $partnerId)
            ->where('type', ListingType::Accommodation)
            ->get()
            ->sortBy(fn (Listing $listing) => $listing->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * The selected property, falling back to the first one the partner owns —
     * a lodge with a single property should never have to choose it.
     */
    public function current(): ?Listing
    {
        if ($this->resolved) {
            return $this->current;
        }

        $this->resolved = true;
        $options = $this->options();
        $stored = $this->user()?->preference(self::PREFERENCE_KEY);

        $this->current = (is_numeric($stored) ? $options->firstWhere('id', (int) $stored) : null)
            ?? $options->first();

        return $this->current;
    }

    /**
     * Remember a choice. An id the partner does not own is not an error worth
     * throwing over — it selects nothing and the fallback applies — but it is
     * never written to the account.
     */
    public function select(?int $listingId): ?Listing
    {
        $chosen = $listingId === null ? null : $this->options()->firstWhere('id', $listingId);

        $this->user()?->rememberPreference(self::PREFERENCE_KEY, $chosen?->id);

        $this->resolved = false;
        $this->current = null;

        return $this->current();
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** Whether the switcher is worth showing at all. */
    public function hasChoice(): bool
    {
        return $this->options()->count() > 1;
    }
}
