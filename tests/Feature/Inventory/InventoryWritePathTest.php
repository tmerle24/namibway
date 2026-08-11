<?php

namespace Tests\Feature\Inventory;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\DirectInventoryWriteException;
use App\Models\Concerns\GuardsInventoryWrites;
use App\Models\InventoryBlock;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RatePlanDay;
use App\Models\RatePlanGuestAmount;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\ReservationNight;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\RoomTypeCalendarDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * "Every inventory mutation goes through InventoryWriter" — enforced, not
 * merely agreed. Two layers, because neither one alone is enough:
 *
 * 1. A runtime guard on the models, which catches Eloquent saves and deletes
 *    from anywhere, including a Filament action nobody has written yet.
 * 2. A source scan, because query-builder writes fire no model events and so
 *    slip past the runtime guard entirely.
 */
class InventoryWritePathTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables that may only be written from the one write path.
     *
     * @var array<int, string>
     */
    private const INVENTORY_TABLES = [
        'room_type_calendar_days',
        'rate_plan_days',
        'rate_plan_guest_amounts',
        'reservations',
        'reservation_units',
        'reservation_nights',
        'reservation_guests',
        'inventory_blocks',
    ];

    /**
     * Models whose writes are guarded at runtime.
     *
     * @var array<int, class-string>
     */
    private const INVENTORY_MODELS = [
        RoomTypeCalendarDay::class,
        RatePlanDay::class,
        RatePlanGuestAmount::class,
        Reservation::class,
        ReservationUnit::class,
        ReservationNight::class,
        ReservationGuest::class,
        InventoryBlock::class,
    ];

    /**
     * Where inventory writes are legitimate: the write path itself, and the
     * migrations that create the tables in the first place.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PATHS = [
        'app/Services/Inventory',
        'database/migrations',
    ];

    public function test_creating_a_reservation_outside_the_writer_is_refused(): void
    {
        $listing = Listing::factory()->create();

        $this->expectException(DirectInventoryWriteException::class);

        Reservation::create([
            'reference' => 'NW-SNEAKY1',
            'listing_id' => $listing->id,
            'status' => StayStatus::Confirmed,
            'source' => ReservationSource::WalkIn,
            'guest_name' => 'Bypass',
            'check_in' => '2026-09-01',
            'check_out' => '2026-09-02',
            'currency' => 'NAD',
        ]);
    }

    public function test_writing_a_calendar_day_outside_the_writer_is_refused(): void
    {
        $room = RoomType::factory()->create(['listing_id' => Listing::factory()]);

        $this->expectException(DirectInventoryWriteException::class);

        RoomTypeCalendarDay::create([
            'room_type_id' => $room->id,
            'date' => '2026-09-01',
            'units_sold' => 99,
        ]);
    }

    /**
     * A rate is money, and money written from five places is money nobody can
     * account for. The rate calendar is guarded exactly like the inventory one.
     */
    public function test_writing_a_rate_outside_the_writer_is_refused(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create(['listing_id' => $listing->id]);
        $plan = RatePlan::ensureDefaultFor($listing);

        $this->expectException(DirectInventoryWriteException::class);

        RatePlanDay::create([
            'rate_plan_id' => $plan->id,
            'room_type_id' => $room->id,
            'date' => '2026-09-01',
            'rate' => 1,
        ]);
    }

    /** A price by guest count is a rate under another name, and guarded alike. */
    public function test_writing_a_guest_amount_outside_the_writer_is_refused(): void
    {
        $listing = Listing::factory()->create();
        $room = RoomType::factory()->create(['listing_id' => $listing->id]);
        $plan = RatePlan::ensureDefaultFor($listing);

        $this->expectException(DirectInventoryWriteException::class);

        RatePlanGuestAmount::create([
            'rate_plan_id' => $plan->id,
            'room_type_id' => $room->id,
            'date' => '2026-09-01',
            'guests' => 2,
            'amount' => 1,
        ]);
    }

    public function test_creating_a_block_outside_the_writer_is_refused(): void
    {
        $room = RoomType::factory()->create(['listing_id' => Listing::factory()]);

        $this->expectException(DirectInventoryWriteException::class);

        InventoryBlock::create([
            'room_type_id' => $room->id,
            'reason' => BlockReason::Maintenance,
            'units' => 1,
            'first_night' => '2026-09-01',
            'last_night' => '2026-09-02',
        ]);
    }

    public function test_every_inventory_model_is_guarded(): void
    {
        foreach (self::INVENTORY_MODELS as $model) {
            $this->assertContains(
                GuardsInventoryWrites::class,
                class_uses_recursive($model),
                "[{$model}] holds inventory but is not guarded — add the GuardsInventoryWrites trait."
            );
        }
    }

    public function test_nothing_outside_the_write_path_writes_the_inventory_tables(): void
    {
        $offences = [];

        foreach ($this->sourceFiles() as $path) {
            $relative = str_replace(base_path().'/', '', $path);

            if ($this->isAllowed($relative)) {
                continue;
            }

            $contents = File::get($path);

            foreach (self::INVENTORY_TABLES as $table) {
                // A query-builder write fires no model events, so the runtime
                // guard cannot see it. Reaching for the table by name outside
                // the write path is the signature of exactly that.
                if (preg_match('/DB::table\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $contents) === 1) {
                    $offences[] = "{$relative} builds a query against [{$table}] directly.";
                }
            }

            foreach (self::INVENTORY_MODELS as $model) {
                $short = class_basename($model);
                $writers = ['create', 'insert', 'insertOrIgnore', 'updateOrCreate', 'firstOrCreate', 'upsert', 'destroy'];

                foreach ($writers as $writer) {
                    if (preg_match('/\b'.$short.'::'.$writer.'\(/', $contents) === 1) {
                        $offences[] = "{$relative} calls {$short}::{$writer}() directly.";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offences,
            "Inventory has exactly one write path (App\\Services\\Inventory\\InventoryWriter).\n"
            .'Add the operation there instead:'."\n - ".implode("\n - ", $offences)
        );
    }

    /**
     * @return array<int, string>
     */
    private function sourceFiles(): array
    {
        $files = [];

        foreach ([app_path(), database_path()] as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getRealPath();
                }
            }
        }

        return $files;
    }

    private function isAllowed(string $relativePath): bool
    {
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_starts_with($relativePath, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
