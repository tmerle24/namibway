<?php

namespace App\Filament\Partner\Support;

use App\Enums\NoticeLevel;
use App\Filament\Partner\Pages\GettingStarted;
use App\Filament\Partner\Pages\RatesAndAvailability;
use App\Filament\Partner\Resources\ListingResource;
use App\Models\Listing;
use App\Models\RoomType;
use App\Services\Booking\BookingMailbox;

/**
 * Everything the panel has to say to the operator of one property, in one
 * place.
 *
 * This exists because the alternative was a banner per subject pinned above
 * every screen. One of those was already there — where a booking's mail goes —
 * and it was wrong in two ways: it pushed the whole panel down and fought the
 * sticky page header on every scroll, and it had nowhere to put the sentence
 * that actually helps, which is *what to do about it*. A board on the dashboard
 * has room for both, and it is somewhere a new hint can be added without
 * another strip of colour appearing above the logo.
 *
 * The rule for what belongs here: a notice must be **true right now, about this
 * property, and either actionable or explainable**. Nothing that is merely a
 * good idea, and nothing that stays on screen once it has been dealt with —
 * each disappears by itself when its condition stops holding, so nobody has to
 * remember to turn it off.
 *
 * Lives in the panel's own namespace rather than under App\Services because
 * deciding what to tell an operator, and which screen to send them to, is
 * presentation: it reads services, and it links to pages.
 */
class PropertyNotices
{
    public function __construct(private readonly PropertySetup $setup = new PropertySetup) {}

    /**
     * @return array<int, Notice> loudest first
     */
    public function for(?Listing $property): array
    {
        if ($property === null) {
            return [];
        }

        $notices = array_values(array_filter([
            $this->bookingState($property),
            $this->noRooms($property),
            $this->noPrices($property),
        ]));

        usort($notices, fn (Notice $a, Notice $b) => $a->level->weight() <=> $b->level->weight());

        return $notices;
    }

    /**
     * Whether a booking's mail is reaching anybody.
     *
     * An operator who takes a real booking believing the guest was written to
     * only finds out when the guest arrives without a confirmation. So it is a
     * warning while a property has not been switched on, and merely a note in
     * test mode — there, mail being held back is the point of the mode rather
     * than a surprise.
     */
    private function bookingState(Listing $property): ?Notice
    {
        $sentence = (new BookingMailbox($property))->holdingNotice();

        if ($sentence === null) {
            return null;
        }

        $isDemo = $this->setup->bookingState($property) === 'demo';

        return new Notice(
            key: 'booking-state',
            level: $isDemo ? NoticeLevel::Info : NoticeLevel::Warning,
            title: $isDemo ? 'Test mode is on' : 'Bookings are not live yet',
            body: $sentence,
            actionLabel: 'How this is switched on',
            actionUrl: GettingStarted::getUrl(),
        );
    }

    /**
     * A property with nothing to sell. Every other screen in the panel is
     * empty until this is answered, so it is the notice that says where to
     * start.
     */
    private function noRooms(Listing $property): ?Notice
    {
        if ($this->setup->hasRooms($property)) {
            return null;
        }

        return new Notice(
            key: 'no-rooms',
            level: NoticeLevel::Action,
            title: 'Add the rooms you sell',
            body: 'The calendar, the rates and the arrivals board all read room types, so nothing '
                .'can be booked until this property has at least one.',
            actionLabel: 'Add a room type',
            actionUrl: ListingResource::getUrl('edit', ['record' => $property]),
        );
    }

    /** A room nobody has priced, which the writer refuses to sell. */
    private function noPrices(Listing $property): ?Notice
    {
        $unpriced = $this->setup->unpricedRooms($property);

        if ($unpriced === []) {
            return null;
        }

        $names = implode(', ', array_map(fn (RoomType $room) => $room->name, $unpriced));

        return new Notice(
            key: 'no-prices',
            level: NoticeLevel::Action,
            title: count($unpriced) === 1 ? 'One room has no price' : count($unpriced).' rooms have no price',
            body: 'A stay cannot be sold at a price nobody has set, so these are refused at the '
                ."counter rather than sold for nothing: {$names}.",
            actionLabel: 'Set rates',
            actionUrl: RatesAndAvailability::getUrl(),
        );
    }
}
