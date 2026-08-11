<?php

namespace App\Services\Demo;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Support\CountrySettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds a sandbox partner from a real listing, so a meeting starts with the
 * prospect's own lodge already running in the system instead of an empty grid.
 *
 * ## What it copies, and what it deliberately does not
 *
 * The demo gets its **own unpublished copy** of the listing rather than being
 * pointed at the real row. That is not tidiness — room types belong to a
 * listing, so copied rooms would otherwise attach to a published listing and
 * appear on namibway.com, and every edit a prospect made while playing with
 * the calendar would be an edit to real content.
 *
 * It inherits no way of reaching anyone: no contact email, no phone, no
 * website, and no connector credentials. This is the whole of the safety
 * story, and it is deliberately data rather than machinery — there is no
 * outbound-suppression layer to forget to switch on, because a demo tenant
 * simply contains no third party's address. Mail from a demo behaves like
 * mail from anywhere else; it is just addressed to us.
 *
 * ## Rebuilding
 *
 * Running it again on the same source wipes that tenant's book and rebuilds
 * it. Prospects make a mess, and the next meeting has to start clean. The
 * wipe goes through InventoryWriter::purgeProperty(), which refuses any
 * property that is not a demo tenant — so this code path cannot erase a real
 * lodge even if it is called with the wrong listing.
 */
class DemoTenantBuilder
{
    /** Fixed so two runs of the same demo produce the same-looking business. */
    private const RANDOM_SEED = 20260811;

    /** Days of history before today, so the calendar does not start abruptly. */
    private const LOOKBACK_DAYS = 14;

    public function __construct(private readonly InventoryWriter $writer) {}

    /**
     * Create or rebuild the demo tenant for one source listing.
     */
    public function build(Listing $source, ?int $weeks = null, ?string $password = null): DemoTenant
    {
        $weeks = max(2, $weeks ?? (int) config('booking.demo.weeks', 12));

        if ($source->roomTypes()->count() === 0) {
            throw new RuntimeException(
                "[{$source->name}] has no room types, so there is no inventory to demonstrate. "
                .'Add room types to the real listing first, or pick a listing that has them.'
            );
        }

        $partner = $this->partner($source);
        $listing = $this->listing($partner, $source);

        // Everything from a previous run goes before anything new is written,
        // so a rebuild is a rebuild and not a second layer of stays on top of
        // the first.
        $this->wipe($listing);

        $rooms = $this->copyRoomTypes($source, $listing);

        mt_srand(self::RANDOM_SEED);

        // `weeks` is weeks of *coming* business, which is what a prospect
        // scrolls through. The fortnight behind today is extra, and it is
        // there so the calendar opens with departed guests and a history
        // rather than starting abruptly at today.
        $today = CountrySettings::for($listing)->today();
        $start = $today->copy()->subDays(self::LOOKBACK_DAYS);
        $end = $today->copy()->addWeeks($weeks);

        $this->seasons($rooms, $start, $today, $end);
        $blocks = $this->blocks($rooms, $today, $end);
        $stays = $this->stays($listing, $rooms, $start, $end);

        $password ??= $this->readablePassword();
        $user = $this->user($partner, $listing, $password);

        return new DemoTenant(
            partner: $partner,
            listing: $listing,
            source: $source,
            user: $user,
            password: $password,
            signInUrl: $this->signInUrl($user),
            roomTypes: count($rooms),
            stays: $stays,
            blocks: $blocks,
        );
    }

    /**
     * Every demo tenant that exists, newest first.
     *
     * @return Collection<int, Partner>
     */
    public function existing(): Collection
    {
        return Partner::query()
            ->where('is_demo', true)
            ->with('demoSourceListing')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Remove a demo tenant altogether — its book, its rooms, its listing copy,
     * its users and the partner itself.
     *
     * Refuses a partner that is not a demo tenant. The guard is repeated here
     * rather than left to purgeProperty(), because this method also deletes
     * listings and users, which purgeProperty() knows nothing about.
     */
    public function destroy(Partner $partner): void
    {
        if (! $partner->is_demo) {
            throw new RuntimeException("Partner [{$partner->id}] is not a demo tenant.");
        }

        DB::transaction(function () use ($partner) {
            foreach ($partner->listings()->get() as $listing) {
                $this->wipe($listing);
                $listing->roomTypes()->delete();
                $listing->delete();
            }

            User::query()->where('partner_id', $partner->id)->delete();
            $partner->delete();
        });
    }

    /**
     * The demo partner for a source listing, created if it is not there yet.
     *
     * The contact columns are cleared on every run, not only at creation: if
     * somebody ever fills one in by hand, a rebuild takes it back out.
     */
    private function partner(Listing $source): Partner
    {
        $partner = Partner::query()
            ->where('is_demo', true)
            ->where('demo_source_listing_id', $source->id)
            ->first() ?? new Partner;

        $partner->fill([
            'name' => Str::limit($source->name, 80, '').' (Demo)',
            'is_demo' => true,
            'demo_source_listing_id' => $source->id,
            'email' => null,
            'phone' => null,
            'website' => null,
            'instagram' => null,
            'facebook' => null,
            'source_url' => null,
            'claim_token' => null,
        ]);

        // Not fill(): a connector is credentials, and clearing it is worth
        // saying out loud rather than hiding among twelve other keys.
        $partner->setConnectorSetup(null, [], trustedSource: true);
        $partner->save();

        return $partner;
    }

    /**
     * The demo's own copy of the listing.
     *
     * Only what the lodge-facing screens and a recognisable admin record need
     * is copied. Contact details, publication state, scrape provenance and
     * claim state are all deliberately left behind — a demo is not a second
     * copy of a real business record, it is a stage set.
     */
    private function listing(Partner $partner, Listing $source): Listing
    {
        $listing = $partner->listings()->first() ?? new Listing;

        $listing->fill([
            'partner_id' => $partner->id,
            'type' => $source->type,
            'slug' => $listing->exists ? $listing->slug : $this->slug($source),
            'city_id' => $source->city_id,
            'latitude' => $source->latitude,
            'longitude' => $source->longitude,
            'address' => $source->address,
            'image' => $source->image,
            'gallery' => $source->gallery,
            'facilities' => $source->facilities,
            'activities' => $source->activities,
            'languages' => $source->languages,
            'price_from' => $source->price_from,
            'price_currency' => $source->price_currency,
            'price_unit' => $source->price_unit,

            // A demo must never be reachable by a traveller, and must never
            // take a real booking request.
            'is_published' => false,
            'accepts_inquiries' => false,
            'is_featured' => false,
            'is_homepage_pick' => false,
        ]);

        $listing->setTranslations('name', $source->getTranslations('name'));
        $listing->save();

        // description is written through a mutator that HTML-escapes plain
        // text, so assigning an already-stored value would escape it a second
        // time and the prospect would read "children &lt;12 free". Copying the
        // stored bytes is the only faithful way to duplicate it.
        $raw = $source->getAttributes()['description'] ?? null;
        DB::table('listings')->where('id', $listing->id)->update(['description' => $raw]);

        return $listing->refresh();
    }

    /**
     * A slug nobody will confuse with the real listing, and that stays free
     * even if two prospects' lodges are named the same thing.
     */
    private function slug(Listing $source): string
    {
        $base = 'demo-'.Str::slug(Str::limit($source->slug, 60, ''));
        $slug = $base;
        $suffix = 2;

        while (Listing::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Copies rather than references, so that editing demo inventory cannot
     * reach real content. Rebuilt from scratch each run — the source is the
     * truth, and a room type a prospect renamed should come back.
     *
     * @return array<int, RoomType>
     */
    private function copyRoomTypes(Listing $source, Listing $listing): array
    {
        $listing->roomTypes()->delete();

        $rooms = [];

        foreach ($source->roomTypes()->orderBy('id')->get() as $room) {
            $rooms[] = RoomType::create([
                'listing_id' => $listing->id,
                'code' => $room->code,
                'name' => $room->name,
                'description' => $room->description,
                'gallery' => $room->gallery,
                'max_adults' => $room->max_adults,
                'max_children' => $room->max_children,
                'total_units' => $room->total_units,
                'rate_per_night' => $room->rate_per_night,
                'currency' => $room->currency,
                'is_active' => $room->is_active,
            ]);
        }

        return $rooms;
    }

    /** The demo property's book, gone. Guarded inside the writer. */
    private function wipe(Listing $listing): void
    {
        $this->writer->purgeProperty($listing);
    }

    /**
     * Two seasons and the two restrictions a front desk actually uses, so the
     * calendar shows rates that move and markers that mean something.
     *
     * @param  array<int, RoomType>  $rooms
     */
    private function seasons(array $rooms, Carbon $start, Carbon $today, Carbon $end): void
    {
        // The last night in the window; setCalendar counts nights and is
        // inclusive at both ends, unlike $end, which is a boundary.
        $lastNight = $end->copy()->subDay();

        // The boundary sits a little way into the coming weeks rather than at
        // a fixed 42 days, so a short demo still shows one — a rate that never
        // changes teaches a prospect nothing about seasons, and a boundary
        // past the end of the window is a backwards range.
        $ahead = max(1, (int) $today->diffInDays($lastNight));
        $highSeason = $today->copy()->addDays(max(3, (int) round($ahead * 0.4)));

        if ($highSeason->gt($lastNight)) {
            $highSeason = $lastNight->copy();
        }

        foreach ($rooms as $room) {
            $base = round((float) $room->rate_per_night, 2);

            $this->writer->setCalendar($room, $start, $highSeason->copy()->subDay(), ['rate' => $base]);
            $this->writer->setCalendar($room, $highSeason, $lastNight, ['rate' => round($base * 1.6, 2)]);

            // Weekends carry a surcharge — the reason the bulk editor has a
            // weekday filter at all.
            $this->writer->setCalendar($room, $start, $lastNight, ['rate' => round($base * 1.25, 2)], [5, 6]);

            $this->writer->setCalendar($room, $highSeason, $highSeason, ['closed_to_arrival' => true]);

            $peakFriday = $highSeason->copy()->next(Carbon::FRIDAY);

            if ($peakFriday->lte($lastNight)) {
                $peakSaturday = $peakFriday->copy()->addDay();
                $this->writer->setCalendar(
                    $room,
                    $peakFriday,
                    $peakSaturday->gt($lastNight) ? $peakFriday : $peakSaturday,
                    ['min_stay' => 2],
                );
            }
        }
    }

    /**
     * @param  array<int, RoomType>  $rooms
     */
    private function blocks(array $rooms, Carbon $today, Carbon $end): int
    {
        $created = 0;
        $lastNight = $end->copy()->subDay();

        // Close enough to today that they are on screen when the calendar
        // opens, rather than something a prospect has to go looking for.
        $plans = [
            [0, BlockReason::Maintenance, 'Re-thatching two units.', 5, 9],
            [count($rooms) - 1, BlockReason::OwnerUse, 'Owner staying over.', 15, 17],
        ];

        foreach ($plans as [$index, $reason, $note, $from, $to]) {
            $room = $rooms[$index] ?? null;
            $firstNight = $today->copy()->addDays($from);

            if ($room === null || $firstNight->gt($lastNight)) {
                continue;
            }

            $lastBlockNight = $today->copy()->addDays($to);

            if ($lastBlockNight->gt($lastNight)) {
                $lastBlockNight = $lastNight->copy();
            }

            // Never the whole room type: a demo where a row is entirely off
            // sale looks broken rather than busy.
            $units = max(1, (int) floor($room->total_units / 2));

            try {
                $this->writer->block(new BlockRequest(
                    roomType: $room,
                    units: $units,
                    firstNight: $firstNight,
                    lastNight: $lastBlockNight,
                    reason: $reason,
                    note: $note,
                ));
                $created++;
            } catch (InventoryUnavailableException) {
                // A small room type already full on those nights is the demo
                // meeting its own rules, not a failure.
                continue;
            }
        }

        return $created;
    }

    /**
     * Stays across the window, each in the lifecycle state it would really be
     * in by now: departed in the past, in house today, expected later.
     *
     * @param  array<int, RoomType>  $rooms
     */
    private function stays(Listing $listing, array $rooms, Carbon $start, Carbon $end): int
    {
        $today = CountrySettings::for($listing)->today();
        $created = 0;

        for ($night = $start->copy(); $night->lt($end); $night = $night->addDay()) {
            // Enough arrivals to look like a working lodge, few enough that a
            // prospect clicking around can still book something.
            for ($i = 0, $arrivals = mt_rand(1, 3); $i < $arrivals; $i++) {
                $room = $rooms[array_rand($rooms)];
                $checkOut = $night->copy()->addDays(mt_rand(1, 4));
                $quantity = $room->total_units > 3 ? mt_rand(1, 2) : 1;

                try {
                    $reservation = $this->writer->book(new BookingRequest(
                        listing: $listing,
                        lines: [new BookingLine($room, $quantity, $night, $checkOut)],
                        guestName: $this->guestName(),
                        guestEmail: $this->guestEmail(),
                        source: $this->source(),
                        adults: max(1, min($room->max_adults, $quantity + 1)),
                        children: mt_rand(0, 1),
                    ));
                } catch (InventoryUnavailableException|StayRuleViolationException) {
                    continue;
                }

                $this->advanceLifecycle($reservation, $today, $night, $checkOut);
                $created++;
            }
        }

        return $created;
    }

    private function advanceLifecycle(Reservation $reservation, Carbon $today, Carbon $checkIn, Carbon $checkOut): void
    {
        if ($checkOut->lte($today)) {
            if (mt_rand(1, 12) === 1) {
                $this->writer->transition($reservation, StayStatus::NoShow);

                return;
            }

            $this->writer->transition($reservation, StayStatus::DueIn);
            $this->writer->transition($reservation, StayStatus::InHouse);
            $this->writer->transition($reservation, StayStatus::CheckedOut);

            return;
        }

        if ($checkIn->lte($today)) {
            $this->writer->transition($reservation, StayStatus::DueIn);
            $this->writer->transition($reservation, StayStatus::InHouse);

            return;
        }

        // Tomorrow's arrivals are already flagged due in, so the arrivals
        // board has something to show on the day after the demo is built.
        if ($checkIn->isSameDay($today->copy()->addDay())) {
            $this->writer->transition($reservation, StayStatus::DueIn);
        }
    }

    /**
     * The account that gets handed a laptop. Rebuilt with a fresh password
     * every run, so a link or a password from a previous meeting stops
     * working when the tenant is reset.
     */
    private function user(Partner $partner, Listing $listing, string $password): User
    {
        $email = 'demo-'.Str::limit($listing->slug, 40, '').'@'.config('booking.demo.email_domain', 'demo.namibway.com');

        $user = User::query()->where('partner_id', $partner->id)->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User;

        $user->forceFill([
            'name' => $partner->name,
            'email' => $email,
            'password' => Hash::make($password),
            'partner_id' => $partner->id,
            'is_admin' => false,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * A signed link that logs the demo account straight in, so a laptop can be
     * handed over in a meeting without anyone typing a password or being sent
     * through a reset flow.
     */
    private function signInUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'partner.demo.sign-in',
            now()->addDays(max(1, (int) config('booking.demo.sign_in_link_days', 14))),
            ['user' => $user->id],
        );
    }

    /** Typed by hand on someone else's laptop, so no ambiguous characters. */
    private function readablePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $password = 'demo-';

        for ($i = 0; $i < 8; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function guestEmail(): string
    {
        return 'guest'.mt_rand(1000, 9999).'@'.config('booking.demo.email_domain', 'demo.namibway.com');
    }

    private function source(): ReservationSource
    {
        return match (mt_rand(1, 10)) {
            1, 2, 3, 4 => ReservationSource::Website,
            5, 6 => ReservationSource::Telephone,
            7 => ReservationSource::WalkIn,
            8 => ReservationSource::Email,
            default => ReservationSource::PartnerEntered,
        };
    }

    private function guestName(): string
    {
        $first = ['Anna', 'Johan', 'Miriam', 'Thomas', 'Selma', 'Petrus', 'Klara', 'Daniel', 'Helena', 'Simon'];
        $last = ['Shipanga', 'Van Wyk', 'Amutenya', 'Beukes', 'Nakale', 'Steyn', 'Hamutenya', 'Du Plessis'];

        return $first[array_rand($first)].' '.$last[array_rand($last)];
    }
}
