<?php

namespace Database\Seeders;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\Listing;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Fills one lodge with a season, room types, blocks and a few weeks of stays,
 * so the lodge-facing screens have something real to render.
 *
 * **Development only.** This is not the prospect-facing demo tenant — that is
 * a later, separate thing, and it should not inherit whatever plausible
 * nonsense is convenient here.
 *
 * Run it on its own:
 *
 *     php artisan db:seed --class=InventoryDemoSeeder
 *
 * Every write goes through InventoryWriter, exactly like production code has
 * to. That is deliberate: a seeder that reached into the tables directly
 * would be the first crack in the single write path, and it would also stop
 * exercising the concurrency and pricing logic it is pretending to produce.
 */
class InventoryDemoSeeder extends Seeder
{
    private const LISTING_SLUG = 'etosha-safari-lodge';

    /** Fixed so two runs on two machines produce the same calendar. */
    private const RANDOM_SEED = 20260811;

    public function run(): void
    {
        $writer = app(InventoryWriter::class);
        $listing = Listing::where('slug', self::LISTING_SLUG)->first();

        if (! $listing) {
            $this->warn('InventoryDemoSeeder: no listing ['.self::LISTING_SLUG.']. Run ListingSeeder first.');

            return;
        }

        // Re-running would stack a second set of stays on top of the first and
        // report an occupancy nobody planned. Refusing is the safe half of
        // that choice; deleting someone's data to make room is not.
        if (Reservation::where('listing_id', $listing->id)->exists()) {
            $this->warn('InventoryDemoSeeder: ['.self::LISTING_SLUG.'] already has stays — nothing seeded.');

            return;
        }

        mt_srand(self::RANDOM_SEED);

        $rooms = $this->roomTypes($listing);
        $start = Carbon::now()->startOfDay()->subDays(14);
        $end = $start->copy()->addDays(70);

        $this->seasons($writer, $rooms, $start, $end);
        $this->blocks($writer, $rooms, $start);
        $created = $this->stays($writer, $listing, $rooms, $start, $end);

        $this->note("InventoryDemoSeeder: {$created} stays across ".count($rooms)." room types at {$listing->name}.");
    }

    /**
     * @return array<int, RoomType>
     */
    private function roomTypes(Listing $listing): array
    {
        $definitions = [
            ['code' => 'STD', 'name' => 'Standard Double', 'units' => 8, 'rate' => 1850.00, 'adults' => 2, 'children' => 1],
            ['code' => 'FAM', 'name' => 'Family Chalet', 'units' => 4, 'rate' => 2950.00, 'adults' => 4, 'children' => 2],
            ['code' => 'LUX', 'name' => 'Luxury Tent', 'units' => 2, 'rate' => 4600.00, 'adults' => 2, 'children' => 0],
        ];

        $rooms = [];

        foreach ($definitions as $definition) {
            $rooms[] = RoomType::updateOrCreate(
                ['listing_id' => $listing->id, 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => 'Seeded for development — not real inventory.',
                    'max_adults' => $definition['adults'],
                    'max_children' => $definition['children'],
                    'total_units' => $definition['units'],
                    'rate_per_night' => $definition['rate'],
                    'currency' => 'NAD',
                    'is_active' => true,
                ]
            );
        }

        return $rooms;
    }

    /**
     * A season is a range written onto dates. High season starts four weeks
     * out, which puts the boundary inside the window a stay can straddle —
     * the case that has to price per night rather than per stay.
     *
     * @param  array<int, RoomType>  $rooms
     */
    private function seasons(InventoryWriter $writer, array $rooms, Carbon $start, Carbon $end): void
    {
        $highSeasonStart = $start->copy()->addDays(42);

        foreach ($rooms as $room) {
            $writer->setCalendar($room, $start, $highSeasonStart->copy()->subDay(), [
                'rate' => round((float) $room->rate_per_night, 2),
                'min_stay' => null,
                'closed_to_arrival' => false,
                'closed_to_departure' => false,
            ]);

            $writer->setCalendar($room, $highSeasonStart, $end, [
                'rate' => round((float) $room->rate_per_night * 1.6, 2),
            ]);

            // A peak weekend that will not take one-nighters, and a changeover
            // day nobody may arrive on — the two restrictions a front desk
            // actually uses.
            $peakFriday = $highSeasonStart->copy()->next(Carbon::FRIDAY);
            $writer->setCalendar($room, $peakFriday, $peakFriday->copy()->addDay(), ['min_stay' => 2]);
            $writer->setCalendar($room, $highSeasonStart, $highSeasonStart, ['closed_to_arrival' => true]);
        }
    }

    /**
     * @param  array<int, RoomType>  $rooms
     */
    private function blocks(InventoryWriter $writer, array $rooms, Carbon $start): void
    {
        $standard = $rooms[0];
        $luxury = $rooms[2];

        $writer->block(new BlockRequest(
            roomType: $standard,
            units: 2,
            firstNight: $start->copy()->addDays(20),
            lastNight: $start->copy()->addDays(24),
            reason: BlockReason::Maintenance,
            note: 'Re-thatching two units.',
        ));

        $writer->block(new BlockRequest(
            roomType: $luxury,
            units: 1,
            firstNight: $start->copy()->addDays(30),
            lastNight: $start->copy()->addDays(32),
            reason: BlockReason::OwnerUse,
            note: 'Owner staying over.',
        ));
    }

    /**
     * Plausible stays across the window, with the lifecycle each one would
     * really be in by now: departed in the past, in house today, expected
     * later.
     *
     * @param  array<int, RoomType>  $rooms
     */
    private function stays(InventoryWriter $writer, Listing $listing, array $rooms, Carbon $start, Carbon $end): int
    {
        $today = Carbon::now()->startOfDay();
        $created = 0;

        for ($night = $start->copy(); $night->lt($end); $night->addDay()) {
            // Roughly two or three arrivals a day — enough to look like a
            // working lodge without filling it to the point where nothing new
            // can be booked while clicking around.
            $arrivals = mt_rand(1, 3);

            for ($i = 0; $i < $arrivals; $i++) {
                $room = $rooms[array_rand($rooms)];
                $nights = mt_rand(1, 4);
                $quantity = $room->code === 'STD' ? mt_rand(1, 2) : 1;
                $checkOut = $night->copy()->addDays($nights);

                try {
                    $reservation = $writer->book(new BookingRequest(
                        listing: $listing,
                        lines: [new BookingLine($room, $quantity, $night, $checkOut)],
                        guestName: $this->guestName(),
                        guestEmail: 'guest'.mt_rand(1000, 9999).'@example.com',
                        source: $this->source(),
                        adults: min($room->max_adults, $quantity + 1),
                        children: mt_rand(0, 1) === 1 ? 1 : 0,
                    ));
                } catch (InventoryUnavailableException|StayRuleViolationException) {
                    // A full night or a refused restriction is the seeder
                    // meeting its own rules, not an error.
                    continue;
                }

                $this->advanceLifecycle($writer, $reservation, $today, $night, $checkOut);
                $created++;
            }
        }

        return $created;
    }

    private function advanceLifecycle(InventoryWriter $writer, Reservation $reservation, Carbon $today, Carbon $checkIn, Carbon $checkOut): void
    {
        if ($checkOut->lte($today)) {
            // A handful of past arrivals never showed up — the state a desk
            // has to be able to record.
            if (mt_rand(1, 12) === 1) {
                $writer->transition($reservation, StayStatus::NoShow);

                return;
            }

            $writer->transition($reservation, StayStatus::DueIn);
            $writer->transition($reservation, StayStatus::InHouse);
            $writer->transition($reservation, StayStatus::CheckedOut);

            return;
        }

        if ($checkIn->lte($today)) {
            $writer->transition($reservation, StayStatus::DueIn);
            $writer->transition($reservation, StayStatus::InHouse);

            return;
        }

        if ($checkIn->isSameDay($today->copy()->addDay())) {
            $writer->transition($reservation, StayStatus::DueIn);
        }
    }

    /**
     * Seeder::$command is set by every supported entry point — the db:seed
     * command, Seeder::call(), and $this->seed() in tests — so it is spoken to
     * directly rather than guarded.
     */
    private function note(string $message): void
    {
        $this->command->info($message);
    }

    private function warn(string $message): void
    {
        $this->command->warn($message);
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
