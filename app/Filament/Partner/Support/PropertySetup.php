<?php

namespace App\Filament\Partner\Support;

use App\Filament\Partner\Pages\RatesAndAvailability;
use App\Filament\Partner\Resources\ListingResource;
use App\Filament\Partner\Resources\RatePlanResource;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * How far a property is from being able to take a booking.
 *
 * One class answers it because two screens ask: the dashboard's notice board
 * shows what is still missing, and the Getting started page shows the same
 * questions as a checklist including the parts already done. Asking the same
 * question twice in two places is how the two screens end up disagreeing.
 *
 * Everything here is derived from the tables the screens themselves read.
 * Nothing is stored, nothing is ticked off by hand, and a step undoes itself
 * if the thing it checked goes away.
 */
class PropertySetup
{
    /** Rooms exist to sell. Until then every other screen is empty. */
    public function hasRooms(Listing $property): bool
    {
        return $this->activeRooms($property)->exists();
    }

    /**
     * Rooms nobody has priced.
     *
     * A room is priced if it carries a default rate, or if any rate plan sets
     * a rate for it on any night — where exactly is the rates screen's
     * business. An unpriced room is not a matter of taste: the writer refuses
     * the stay outright, so this turns a refusal at the counter into something
     * seen beforehand.
     *
     * @return array<int, BookableUnit>
     */
    public function unpricedRooms(Listing $property): array
    {
        return $this->activeRooms($property)
            ->where(fn (Builder $query) => $query
                ->whereNull('rate_per_night')
                ->orWhere('rate_per_night', '<=', 0))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from((new RatePlanDay)->getTable())
                ->whereColumn('rate_plan_days.bookable_unit_id', 'bookable_units.id')
                ->whereNotNull('rate_plan_days.rate'))
            ->get()
            ->all();
    }

    /** Whether anybody has looked at what the property actually sells. */
    public function hasRatePlan(Listing $property): bool
    {
        return RatePlan::query()
            ->where('listing_id', $property->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Which of the three booking states this property is in. The states are
     * documented on the migration that introduced them; the difference between
     * them is only ever who receives the mail.
     *
     * @return 'held'|'demo'|'live'
     */
    public function bookingState(Listing $property): string
    {
        $partner = $property->partner;

        if ($partner?->booking_enabled !== true) {
            return 'held';
        }

        return $partner->booking_demo_mode === true ? 'demo' : 'live';
    }

    /**
     * The checklist, in the order the work is actually done.
     *
     * The last step is not one the operator can do — going live means real
     * guests receive real mail in NamibWay's name, so NamibWay switches it on.
     * It is on the list anyway, because a checklist that stops before the
     * finish line leaves somebody wondering whether they missed a screen.
     *
     * @return array<int, SetupStep>
     */
    public function steps(?Listing $property): array
    {
        if ($property === null) {
            return [];
        }

        $unpriced = $this->unpricedRooms($property);
        $hasRooms = $this->hasRooms($property);

        return [
            new SetupStep(
                key: 'rooms',
                title: 'Add the rooms you sell',
                body: 'One room type per kind of room, with how many of them the property has. '
                    .'The calendar, the rates and the arrivals board all read these.',
                done: $hasRooms,
                actionLabel: 'Rooms',
                actionUrl: ListingResource::getUrl('edit', ['record' => $property]),
            ),
            new SetupStep(
                key: 'rates',
                title: 'Set your rates',
                body: 'A season at a time rather than a night at a time, and only the weekdays you '
                    .'mean. A room with no rate cannot be booked at all.',
                done: $hasRooms && $unpriced === [],
                actionLabel: 'Rates',
                actionUrl: RatesAndAvailability::getUrl(),
            ),
            new SetupStep(
                key: 'rate-plan',
                title: 'Say what the rate includes',
                body: 'Bed and breakfast, dinner included, a non-refundable rate — a rate plan is the '
                    .'product a guest buys. One is created for you the first time you set a rate.',
                done: $this->hasRatePlan($property),
                actionLabel: 'Rate plans',
                actionUrl: RatePlanResource::getUrl(),
            ),
            new SetupStep(
                key: 'live',
                title: 'Go live',
                body: 'Until NamibWay switches this property on, confirmations are held rather than sent '
                    .'to guests. Ask when you are ready and it takes a minute.',
                done: $this->bookingState($property) === 'live',
                actionLabel: null,
                actionUrl: null,
            ),
        ];
    }

    /** @return Builder<BookableUnit> */
    private function activeRooms(Listing $property): Builder
    {
        return BookableUnit::query()
            ->where('listing_id', $property->id)
            ->where('is_active', true);
    }
}
