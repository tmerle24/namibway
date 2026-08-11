<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `room_types` is the wrong name for a quad bike, a seat and a Land Cruiser.
 *
 * `BOOKING_BEYOND_ROOMS.md` §3.2 asked for a decision and its reasons. The
 * decision is **`bookable_units`**, and the reason to do it now is that the cost
 * will never be lower: no partner is live, `room_types` is empty in production,
 * and the alternative the brief named is living with the word forever.
 *
 * ## Why `bookable_units` and not `inventory_types`
 *
 * Both were offered. `bookable_units` reads as English in the sentences this
 * code actually writes — "the bookable unit this departure belongs to" — and it
 * is the word the codebase had already drifted to on its own: `BookingSlot::forUnit()`,
 * `DayGrid`'s `$unit`, `DepartureColumn::$unit`, and the exporter's own docblock
 * all said "unit" while the table said "room type". `inventory_types` avoids one
 * wart this name has (see below) at the cost of a phrase nobody says out loud.
 *
 * **The wart, named rather than hidden.** `bookable_units.total_units` is now
 * "how many units of this unit", and `reservation_units` is a booking line
 * holding a quantity of one. Three uses of the word in one schema. Chasing it
 * would mean renaming `total_units` and `reservation_units` as well — a much
 * larger diff for a word that is repeated rather than wrong, on tables whose
 * meaning nobody has ever misread. The type/instance distinction is carried by
 * the sentence rather than by the noun.
 *
 * ## What is deliberately *not* renamed
 *
 * The word survives wherever it is somebody else's, or where it is correct:
 *
 * - **`App\Connectors\ResConnect\DTOs\RoomType`** and the `room_types` key in
 *   the `/api/v1` availability response. That is the *partner's* vocabulary in a
 *   mapping layer — ResRequest, NightsBridge and hopeCloud all say "room type" —
 *   and the response is a contract with API clients, documented in Scribe.
 * - **`/listings/{slug}/room-types`** and its controller method. Traveller-facing,
 *   about a lodge, where "room" is the right word.
 * - **The `RoomTypes` sheet** in the bulk-capture workbook, its column headers and
 *   `RoomTypeSheet`. Content managers have those files on their machines; the
 *   sheet name is an interface with a person, not an identifier.
 * - **"Room type" as a label** in the partner and admin panels. A lodge should
 *   read "Room type". What a tour operator should read instead is a
 *   per-vertical wording question (`BOOKING_BEYOND_ROOMS.md` §3.5), not this one.
 *
 * ## Mechanics
 *
 * Postgres keeps indexes and constraints across `ALTER TABLE ... RENAME TO`, but
 * under their old auto-generated names — leaving `bookable_units` carrying a
 * `room_types_pkey` is a trap for whoever next reads the schema in psql. They are
 * renamed explicitly below, as the regions → destinations migration did. The map
 * is data rather than forty statements so both directions read as one list.
 *
 * One new name would exceed Postgres's 63-byte identifier limit and be silently
 * truncated, so it is given a shorter name of its own rather than left to chance.
 */
return new class extends Migration
{
    /**
     * Old table => new table.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'room_types' => 'bookable_units',
        'room_type_calendar_days' => 'bookable_unit_calendar_days',
        // Laravel infers a pivot's name from the two models in alphabetical
        // order, so both of these move — one keeps its position and one does not.
        'amenity_room_type' => 'amenity_bookable_unit',
        'promotion_room_type' => 'bookable_unit_promotion',
    ];

    /**
     * Table => the columns on it that name the sellable thing.
     *
     * @var array<string, array<string, string>>
     */
    private const COLUMNS = [
        'bookable_unit_calendar_days' => ['room_type_id' => 'bookable_unit_id'],
        'amenity_bookable_unit' => ['room_type_id' => 'bookable_unit_id'],
        'bookable_unit_promotion' => ['room_type_id' => 'bookable_unit_id'],
        'booking_slots' => ['room_type_id' => 'bookable_unit_id'],
        'inventory_blocks' => ['room_type_id' => 'bookable_unit_id'],
        'rate_plan_days' => ['room_type_id' => 'bookable_unit_id'],
        'rate_plan_guest_amounts' => ['room_type_id' => 'bookable_unit_id'],
        'reservation_units' => ['room_type_id' => 'bookable_unit_id'],
        // The traveller's request names the room it asked for by code, matched
        // against the unit's own `code`. Renamed for the same reason as the rest:
        // it is an internal column, and the wire format the trip plan posts is
        // `room_selection`, which is untouched.
        'inquiries' => ['room_type_code' => 'bookable_unit_code'],
    ];

    /**
     * Old identifier => new identifier, for indexes and constraints alike.
     *
     * @var array<string, string>
     */
    private const IDENTIFIERS = [
        // room_types
        'room_types_pkey' => 'bookable_units_pkey',
        'room_types_listing_id_code_unique' => 'bookable_units_listing_id_code_unique',
        'room_types_listing_id_foreign' => 'bookable_units_listing_id_foreign',

        // the ARI calendar
        'room_type_calendar_days_pkey' => 'bookable_unit_calendar_days_pkey',
        'room_type_calendar_days_room_type_id_foreign' => 'bookable_unit_calendar_days_bookable_unit_id_foreign',
        'room_type_calendar_days_slot_id_foreign' => 'bookable_unit_calendar_days_slot_id_foreign',
        'room_type_calendar_days_units_non_negative' => 'bookable_unit_calendar_days_units_non_negative',
        'room_type_calendar_days_within_override' => 'bookable_unit_calendar_days_within_override',

        // pivots
        'amenity_room_type_pkey' => 'amenity_bookable_unit_pkey',
        'amenity_room_type_amenity_id_foreign' => 'amenity_bookable_unit_amenity_id_foreign',
        'amenity_room_type_room_type_id_foreign' => 'amenity_bookable_unit_bookable_unit_id_foreign',
        'amenity_room_type_amenity_id_room_type_id_unique' => 'amenity_bookable_unit_amenity_id_bookable_unit_id_unique',
        'promotion_room_type_pkey' => 'bookable_unit_promotion_pkey',
        'promotion_room_type_promotion_id_foreign' => 'bookable_unit_promotion_promotion_id_foreign',
        'promotion_room_type_room_type_id_foreign' => 'bookable_unit_promotion_bookable_unit_id_foreign',
        'promotion_room_type_promotion_id_room_type_id_unique' => 'bookable_unit_promotion_promotion_id_bookable_unit_id_unique',

        // everything that points at a unit
        'booking_slots_room_type_id_foreign' => 'booking_slots_bookable_unit_id_foreign',
        'booking_slots_room_type_id_starts_at_unique' => 'booking_slots_bookable_unit_id_starts_at_unique',
        'booking_slots_room_type_id_is_active_sort_index' => 'booking_slots_bookable_unit_id_is_active_sort_index',
        'inventory_blocks_room_type_id_foreign' => 'inventory_blocks_bookable_unit_id_foreign',
        'inventory_blocks_room_type_id_first_night_last_night_index' => 'inventory_blocks_bookable_unit_id_first_night_last_night_index',
        'rate_plan_days_room_type_id_foreign' => 'rate_plan_days_bookable_unit_id_foreign',
        'rate_plan_guest_amounts_room_type_id_foreign' => 'rate_plan_guest_amounts_bookable_unit_id_foreign',
        // 64 bytes under the mechanical rename, which Postgres would truncate
        // silently. Named deliberately instead.
        'rate_plan_guest_amounts_rate_plan_id_room_type_id_date_index' => 'rate_plan_guest_amounts_plan_unit_date_index',
        'reservation_units_room_type_id_foreign' => 'reservation_units_bookable_unit_id_foreign',
        'reservation_units_room_type_id_check_in_check_out_index' => 'reservation_units_bookable_unit_id_check_in_check_out_index',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $from => $to) {
            Schema::rename($from, $to);
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $from => $to) {
                    $blueprint->renameColumn($from, $to);
                }
            });
        }

        $this->renameIdentifiers(self::IDENTIFIERS);
    }

    public function down(): void
    {
        $this->renameIdentifiers(array_flip(self::IDENTIFIERS));

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $from => $to) {
                    $blueprint->renameColumn($to, $from);
                }
            });
        }

        foreach (array_reverse(self::TABLES, true) as $from => $to) {
            Schema::rename($to, $from);
        }
    }

    /**
     * Indexes and constraints share a namespace in Postgres and are renamed by
     * different statements, so each name is tried as both and the one that does
     * not apply is skipped. Only Postgres names them this way; SQLite does not,
     * which is why the whole step is driver-guarded.
     *
     * @param  array<string, string>  $names
     */
    private function renameIdentifiers(array $names): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($names as $from => $to) {
            $isIndex = DB::selectOne(
                'select 1 from pg_class c join pg_namespace n on n.oid = c.relnamespace '
                ."where c.relname = ? and c.relkind = 'i' and n.nspname = current_schema()",
                [$from],
            );

            if ($isIndex !== null) {
                DB::statement('ALTER INDEX '.$from.' RENAME TO '.$to);

                continue;
            }

            $constraint = DB::selectOne(
                'select conrelid::regclass::text as tbl from pg_constraint where conname = ?',
                [$from],
            );

            if ($constraint !== null) {
                DB::statement('ALTER TABLE '.$constraint->tbl.' RENAME CONSTRAINT '.$from.' TO '.$to);
            }
        }
    }
};
