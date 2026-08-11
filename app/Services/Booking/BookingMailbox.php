<?php

namespace App\Services\Booking;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use Illuminate\Support\Str;

/**
 * Where mail about a booking goes — the one place that decides.
 *
 * It is one class because the question has one answer and getting it wrong is
 * expensive in a way that is invisible until it is not: a lodge that has not
 * been switched on yet must never have a confirmation go out to a guest in its
 * name, and an operator testing the system against their own real inventory
 * must never mail a real guest either. Both failures look like nothing at all
 * until somebody arrives expecting a room.
 *
 * So the rule is stated once, here, and every sender asks rather than decides:
 *
 * | Partner state | Guest mail | Property mail |
 * |---|---|---|
 * | Not enabled (default) | team mailbox | team mailbox |
 * | Demo mode | the demo address | the demo address |
 * | Enabled | the guest | the lodge |
 *
 * The team mailbox is used with plus-addressing — `team+okonjima-bush-camp@` —
 * so a shared inbox groups by property instead of becoming a pile.
 *
 * A property with no partner at all, and an enabled partner who has given no
 * address, both fall back to the team mailbox. Mail is never simply dropped:
 * a booking nobody was told about is worse than a booking told to the wrong
 * desk, because only one of the two gets noticed.
 */
class BookingMailbox
{
    public function __construct(public readonly Listing $listing) {}

    public static function for(Reservation $reservation): self
    {
        $listing = $reservation->listing ?? $reservation->loadMissing('listing')->listing;

        // A reservation without a listing cannot happen — the column is not
        // nullable — but a mailer is the wrong place to prove it.
        return new self($listing ?? new Listing);
    }

    /** Where the guest's own confirmation goes. */
    public function guest(?string $guestEmail): string
    {
        if (! $this->isLive()) {
            return $this->holdingAddress();
        }

        return $this->firstAddress([$guestEmail]);
    }

    /** Where the lodge's notification goes. */
    public function property(): string
    {
        if (! $this->isLive()) {
            return $this->holdingAddress();
        }

        $partner = $this->partner();

        return $this->firstAddress([$partner?->booking_email, $partner?->email]);
    }

    /**
     * Whether real people are being written to. False is the safe state and
     * the default; a partner is switched on by NamibWay, not by themselves.
     */
    public function isLive(): bool
    {
        $partner = $this->partner();

        return $partner?->booking_enabled === true && $partner->booking_demo_mode !== true;
    }

    /**
     * What to say on screen while mail is not going where it eventually will.
     * Null once a property is live, so the notice disappears by itself.
     */
    public function holdingNotice(): ?string
    {
        if ($this->isLive()) {
            return null;
        }

        $partner = $this->partner();

        if ($partner?->booking_demo_mode === true) {
            return 'Test mode: confirmations for this property go to '.$this->holdingAddress()
                .' and never to a guest.';
        }

        return 'Bookings here are not live yet: confirmations go to NamibWay ('
            .$this->holdingAddress().') rather than to guests.';
    }

    /**
     * Where everything goes while a property is not live: the partner's own
     * demo address in demo mode, and the team mailbox otherwise.
     */
    private function holdingAddress(): string
    {
        $partner = $this->partner();

        if ($partner?->booking_demo_mode !== true) {
            return $this->teamAddress();
        }

        return $this->firstAddress([$partner->booking_demo_email]);
    }

    /**
     * The first address that is actually one, falling back to the team
     * mailbox. Mail is never dropped — a booking nobody was told about is
     * worse than one told to the wrong desk, because only one of the two gets
     * noticed.
     *
     * @param  array<int, string|null>  $candidates
     */
    private function firstAddress(array $candidates): string
    {
        foreach ($candidates as $address) {
            if (is_string($address) && trim($address) !== '') {
                return trim($address);
            }
        }

        return $this->teamAddress();
    }

    /**
     * `team+<property>@namibway.com`. The tag is the property slug, trimmed —
     * plus-addressing has no length rule worth relying on, and a slug of a
     * hundred characters helps nobody read an inbox.
     */
    private function teamAddress(): string
    {
        $address = (string) config('booking.team_address', 'team@namibway.com');
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, 'namibway.com');
        $tag = trim(Str::limit((string) $this->listing->slug, 40, ''), '-');

        return $tag === '' ? $address : $local.'+'.$tag.'@'.$domain;
    }

    private function partner(): ?Partner
    {
        return $this->listing->exists ? $this->listing->partner : null;
    }
}
