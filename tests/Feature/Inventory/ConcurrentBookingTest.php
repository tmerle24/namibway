<?php

namespace Tests\Feature\Inventory;

use App\Enums\ReservationSource;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriteGuard;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Throwable;

/**
 * The non-negotiable: two people cannot both get the last room.
 *
 * This forks real OS processes with their own database connections, because
 * the failure it guards against only exists between concurrent transactions.
 * A single-process test that calls book() twice in a row proves nothing —
 * the second call simply sees the first one's committed result, which is the
 * easy case.
 *
 * DatabaseTruncation rather than RefreshDatabase on purpose: RefreshDatabase
 * wraps each test in an uncommitted transaction, and a forked child on its
 * own connection would not be able to see the listing it is supposed to book.
 *
 * Both races are deliberately **one night long**. A multi-night stay hides
 * the bug this is meant to catch: a naive check-then-write implementation
 * survives it by accident, because blocking on the first night's row means
 * its read of the *second* night already sees the winner's committed row.
 * One night leaves the conditional UPDATE as the only thing standing between
 * two guests and the same bed — which is the claim being tested.
 *
 * Verified by mutation: replacing the conditional UPDATE with a read followed
 * by an unconditional increment makes both of these fail (four winners for
 * one and two units respectively).
 */
class ConcurrentBookingTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Only the tables this test writes.
     *
     * Truncating everything is not an option here: several migrations seed
     * their own reference data (the 14 regions and every city are inserted by
     * `create_regions_table` / `create_cities_table`), and RefreshDatabase
     * does not re-run migrations between tests. A blanket truncation therefore
     * deletes that data for the rest of the run, and every later test that
     * needs a city fails — a long way from here, with a confusing message.
     *
     * @var array<int, string>
     */
    protected array $tablesToTruncate = [
        'reservation_nights',
        'reservation_units',
        'reservations',
        'inventory_blocks',
        'room_type_calendar_days',
        'booking_slots',
        'room_types',
        'listings',
    ];

    private const CONTENDERS = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('Genuine concurrency needs pcntl and posix to fork real processes.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite serialises every write and an in-memory database is not
            // even shared between connections, so a race cannot be expressed.
            // CI runs Postgres, which is where this assertion has to hold.
            $this->markTestSkipped('The concurrency guarantee is asserted against Postgres, as CI runs it.');
        }
    }

    /**
     * Clean up after this class, not just before each test.
     *
     * DatabaseTruncation empties the tables on the way *in*, which leaves the
     * last test's rows committed in the database. Every later test using
     * RefreshDatabase only opens a transaction — it does not re-migrate — so
     * those leftovers stay visible and quietly join other tests' result sets.
     * The rows have to be committed for the forked children to see them, so
     * clearing them here is the price of that.
     */
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_simultaneous_bookings_for_the_last_unit_do_not_both_succeed(): void
    {
        $listing = Listing::factory()->create(['is_published' => true]);
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 1,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
        ]);

        $results = $this->race($listing, $room, '2026-09-01', '2026-09-02');

        $booked = array_keys($results, 'booked', true);
        $soldOut = array_keys($results, 'sold_out', true);
        $unexpected = array_filter($results, fn (string $r) => ! in_array($r, ['booked', 'sold_out'], true));

        $this->assertSame([], $unexpected, 'A contender failed for a reason other than the room being gone: '.json_encode($unexpected));
        $this->assertCount(1, $booked, 'Exactly one of '.self::CONTENDERS.' simultaneous bookings may win.');
        $this->assertCount(self::CONTENDERS - 1, $soldOut);

        // And the database agrees with the count, rather than merely having
        // returned the right answers.
        $this->assertSame(1, Reservation::count());

        $day = RoomTypeCalendarDay::where('room_type_id', $room->id)
            ->whereDate('date', '2026-09-01')
            ->firstOrFail();
        $this->assertSame(1, $day->units_sold);
    }

    public function test_simultaneous_bookings_fill_a_room_type_exactly_to_capacity(): void
    {
        $listing = Listing::factory()->create(['is_published' => true]);
        $room = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 2,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
        ]);

        $results = $this->race($listing, $room, '2026-09-01', '2026-09-02');

        $this->assertCount(2, array_keys($results, 'booked', true), 'Two units means exactly two winners.');
        $this->assertCount(self::CONTENDERS - 2, array_keys($results, 'sold_out', true));

        $day = RoomTypeCalendarDay::where('room_type_id', $room->id)
            ->whereDate('date', '2026-09-01')
            ->firstOrFail();
        $this->assertSame(2, $day->units_sold);
        $this->assertLessThanOrEqual((int) $room->total_units, $day->units_sold);
    }

    /**
     * Fork CONTENDERS processes that all try to book the same nights at the
     * same moment, and collect what each one got.
     *
     * @return array<int, string> child index => 'booked' | 'sold_out' | 'error: ...'
     */
    /**
     * The same guarantee, one level finer: two people cannot both get the last
     * seat on one departure.
     *
     * It is the same conditional UPDATE on the same table, and that is the
     * claim — a departure is an ordinary row with an ordinary counter, not a
     * second mechanism. Worth proving rather than assuming, because the row is
     * now found by three columns instead of two, and a slot filter that missed
     * would sell the last seat to everybody who asked.
     */
    public function test_two_simultaneous_bookings_for_the_last_seat_on_a_departure_do_not_both_succeed(): void
    {
        $listing = Listing::factory()->create(['is_published' => true]);
        $unit = RoomType::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Quad tour',
            'total_units' => 1,
            'rate_per_night' => 950,
            'currency' => 'NAD',
        ]);

        $morning = BookingSlot::create([
            'room_type_id' => $unit->id,
            'label' => 'Morning departure',
            'starts_at' => '09:00',
            'duration_minutes' => 180,
        ]);

        $results = $this->race($listing, $unit, '2026-09-01', '2026-09-02', $morning);

        $booked = array_keys($results, 'booked', true);
        $soldOut = array_keys($results, 'sold_out', true);
        $unexpected = array_filter($results, fn (string $r) => ! in_array($r, ['booked', 'sold_out'], true));

        $this->assertSame([], $unexpected, 'A contender failed for a reason other than the seat being gone: '.json_encode($unexpected));
        $this->assertCount(1, $booked, 'Exactly one of '.self::CONTENDERS.' simultaneous bookings may win the last seat.');
        $this->assertCount(self::CONTENDERS - 1, $soldOut);
        $this->assertSame(1, Reservation::count());

        $departure = RoomTypeCalendarDay::where('room_type_id', $unit->id)
            ->where('slot_id', $morning->id)
            ->whereDate('date', '2026-09-01')
            ->firstOrFail();
        $this->assertSame(1, $departure->units_sold);

        // And the day beside it was never touched: a departure's seats are not
        // the property's nights.
        $this->assertSame(
            0,
            RoomTypeCalendarDay::where('room_type_id', $unit->id)->whereNull('slot_id')->count(),
        );
    }

    private function race(Listing $listing, RoomType $room, string $checkIn, string $checkOut, ?BookingSlot $slot = null): array
    {
        $dir = storage_path('framework/testing/concurrency-'.getmypid());
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);

        // Materialise the calendar rows before racing. Without this the
        // contenders all block on the same INSERT for the missing night, which
        // serialises them *incidentally* and hides whether the counter update
        // is safe — the thing this test exists to prove. With the rows already
        // there, the only contention left is the conditional UPDATE itself.
        if ($slot === null) {
            app(InventoryWriter::class)->setCalendar(
                $room,
                now()->parse($checkIn),
                now()->parse($checkOut),
                ['closed_to_arrival' => false],
            );
        } else {
            // The same materialisation for a departure. Written straight to the
            // table because the bulk calendar editor speaks nights, not
            // departures — and giving it a slot here would be building the next
            // slice inside a test.
            InventoryWriteGuard::allow(fn () => RoomTypeCalendarDay::create([
                'room_type_id' => $room->id,
                'slot_id' => $slot->id,
                'date' => $checkIn,
            ]));
        }

        // A shared wall-clock start, so the children genuinely overlap instead
        // of being spaced out by however long forking took.
        $startAt = microtime(true) + 0.5;

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a contender process.');
            }

            if ($pid === 0) {
                $this->contend($i, $dir, $startAt, $listing, $room, $checkIn, $checkOut, $slot);
            }
        }

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            pcntl_wait($status);
        }

        $results = [];

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $path = $dir.'/child-'.$i;
            $results[$i] = File::exists($path) ? trim(File::get($path)) : 'error: no result written';
        }

        File::deleteDirectory($dir);

        return $results;
    }

    /**
     * Runs inside a forked child and never returns.
     */
    private function contend(int $index, string $dir, float $startAt, Listing $listing, RoomType $room, string $checkIn, string $checkOut, ?BookingSlot $slot = null): never
    {
        $result = 'error: never ran';

        try {
            // The child inherited the parent's socket; every process needs its
            // own connection or they would corrupt each other's protocol state.
            DB::purge();
            DB::reconnect();

            while (microtime(true) < $startAt) {
                usleep(100);
            }

            app(InventoryWriter::class)->book(new BookingRequest(
                listing: $listing,
                lines: [new BookingLine($room, 1, now()->parse($checkIn), now()->parse($checkOut), slot: $slot)],
                guestName: 'Contender '.$index,
                source: ReservationSource::Website,
            ));

            $result = 'booked';
        } catch (InventoryUnavailableException) {
            $result = 'sold_out';
        } catch (Throwable $e) {
            $result = 'error: '.$e::class.' — '.$e->getMessage();
        }

        file_put_contents($dir.'/child-'.$index, $result);

        // SIGKILL rather than exit(): the commit is already durable by the
        // time book() returns, and letting PHPUnit's shutdown handlers run in
        // a forked child would duplicate the test run's output and summary.
        posix_kill(getmypid(), SIGKILL);

        exit(1); // @phpstan-ignore deadCode.unreachable (SIGKILL never returns; this satisfies `never`)
    }
}
