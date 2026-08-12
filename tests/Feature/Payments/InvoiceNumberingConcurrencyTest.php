<?php

namespace Tests\Feature\Payments;

use App\Enums\ReservationSource;
use App\Models\BookableUnit;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use App\Services\Payments\InvoiceIssuer;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Throwable;

/**
 * The non-negotiable: two invoices issued at the same instant get different
 * numbers, and the run has no holes in it.
 *
 * This forks real OS processes with their own database connections, for the
 * same reason ConcurrentBookingTest does: the failure it guards against only
 * exists *between* concurrent transactions. A single-process test that issues
 * twice in a row proves nothing — the second call simply reads the first
 * one's committed counter, which is the easy case and the one every broken
 * implementation also passes.
 *
 * What it would catch: replacing `lockForUpdate()` with a plain read, or with
 * `max(sequence_number) + 1`. Both look right in a single process and hand two
 * simultaneous check-outs the same number — after which nobody finds out until
 * a tax inspector has both documents on the desk.
 *
 * DatabaseTruncation rather than RefreshDatabase, because the forked children
 * need to see committed rows.
 */
class InvoiceNumberingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Only the tables this test writes — a blanket truncation would delete the
     * reference data seeded by the regions and cities migrations, and every
     * later test needing a city would fail a long way from here. Same reasoning
     * as ConcurrentBookingTest.
     *
     * @var array<int, string>
     */
    protected array $tablesToTruncate = [
        'invoices',
        'invoice_sequences',
        'reservation_nights',
        'reservation_units',
        'reservations',
        'bookable_unit_calendar_days',
        'bookable_units',
        'listings',
        'partners',
    ];

    private const CONTENDERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            $this->markTestSkipped('Genuine concurrency needs pcntl and posix to fork real processes.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            // SQLite serialises every write and an in-memory database is not
            // shared between connections, so the race cannot be expressed.
            // CI runs Postgres, which is where this has to hold.
            $this->markTestSkipped('The numbering guarantee is asserted against Postgres, as CI runs it.');
        }
    }

    /**
     * DatabaseTruncation empties on the way *in*, so the last test's committed
     * rows would otherwise stay visible to every later test.
     */
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_simultaneous_issues_take_different_numbers_and_leave_no_gap(): void
    {
        $listing = $this->property();
        $room = $this->room($listing);

        $stays = [];

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $checkIn = Carbon::parse('2026-09-01')->addDays($i * 2);

            $stays[$i] = app(InventoryWriter::class)->book(new BookingRequest(
                listing: $listing,
                lines: [new BookingLine($room, 1, $checkIn, $checkIn->copy()->addDays(2))],
                guestName: 'Guest '.$i,
                source: ReservationSource::WalkIn,
            ))->id;
        }

        $results = $this->race($stays);

        $failures = array_filter($results, fn (string $r): bool => ! ctype_digit($r));
        $this->assertSame([], $failures, 'A contender failed: '.json_encode($failures));

        $numbers = array_map('intval', $results);
        sort($numbers);

        // Different numbers, and a run with nothing missing from it. Both
        // halves matter: uniqueness alone is satisfied by an auto-increment
        // that skips, and that skip is what an auditor asks about.
        $this->assertSame(range(1, self::CONTENDERS), $numbers);

        $this->assertSame(self::CONTENDERS, Invoice::count());
        $this->assertSame(self::CONTENDERS, Invoice::query()->distinct()->count('number'));

        // The counter agrees with what was handed out.
        $this->assertSame(
            self::CONTENDERS + 1,
            (int) DB::table('invoice_sequences')->value('next_number'),
        );
    }

    /**
     * Fork CONTENDERS processes that all issue an invoice at the same moment,
     * and collect the sequence number each one got.
     *
     * @param  array<int, int>  $stayIds
     * @return array<int, string> child index => sequence number, or an error
     */
    private function race(array $stayIds): array
    {
        $dir = storage_path('framework/testing/invoice-numbering-'.getmypid());
        File::ensureDirectoryExists($dir);
        File::cleanDirectory($dir);

        // A shared wall-clock start, so the children genuinely overlap rather
        // than being spaced out by however long forking took.
        $startAt = microtime(true) + 0.5;

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a contender process.');
            }

            if ($pid === 0) {
                $this->contend($i, $dir, $startAt, $stayIds[$i]);
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
    private function contend(int $index, string $dir, float $startAt, int $stayId): never
    {
        $result = 'error: never ran';

        try {
            // The child inherited the parent's socket; every process needs its
            // own connection or they would corrupt each other's protocol state.
            DB::purge();
            DB::reconnect();

            $stay = Reservation::findOrFail($stayId);

            while (microtime(true) < $startAt) {
                usleep(100);
            }

            $result = (string) app(InvoiceIssuer::class)->issueForStay($stay)->sequence_number;
        } catch (Throwable $e) {
            $result = 'error: '.$e::class.' — '.$e->getMessage();
        }

        file_put_contents($dir.'/child-'.$index, $result);

        // SIGKILL rather than exit(): the commit is durable by the time
        // issueForStay() returns, and letting PHPUnit's shutdown handlers run
        // in a forked child would duplicate the run's output and summary.
        posix_kill(getmypid(), SIGKILL);

        exit(1); // @phpstan-ignore deadCode.unreachable (SIGKILL never returns; this satisfies `never`)
    }

    private function property(): Listing
    {
        $partner = Partner::create(['name' => 'Concurrency Camp', 'email' => 'race@example.com']);

        return Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => 'Concurrency Camp',
            'is_published' => true,
        ]);
    }

    private function room(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 5,
            'rate_per_night' => 1000,
            'currency' => 'NAD',
        ]);
    }
}
