<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the calendar in two: inventory stays keyed by room type, rates and
 * restrictions move to rate_plan_days.
 *
 * This is the migration BOOKING_SYSTEM.md says has to happen once and early.
 * Adding a dimension to the table the whole availability logic hangs on is
 * cheap while the table holds a handful of properties and expensive later.
 *
 * ## What happens to what is already there
 *
 * Every listing with room types gets one rate plan marked as the default, and
 * every calendar night that actually said something — a rate, a minimum stay,
 * a closed day — is copied onto it. Nights that only carried counters are not
 * copied: a row with nothing but zeroes on it is the sparse calendar's normal
 * state, and reproducing it under a rate plan would turn "nobody has said
 * anything about this night" into "somebody said nothing".
 *
 * Nothing changes on screen afterwards. Every reader falls back to the
 * default plan, and a listing with no plan at all still resolves its rate
 * from the room type, exactly as before.
 *
 * The board basis of the carried-over plan is left unstated on purpose. These
 * rates predate anyone being asked what they include, and guessing would put
 * a claim about somebody's product into the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // One default plan per listing that has room types. A listing without
        // them cannot have a rate to carry, and a plan for it would be an
        // empty row nobody asked for.
        $listingIds = DB::table('room_types')->distinct()->pluck('listing_id');

        foreach ($listingIds->chunk(200) as $chunk) {
            $rows = [];

            foreach ($chunk as $listingId) {
                $rows[] = [
                    'listing_id' => $listingId,
                    'name' => 'Standard rate',
                    'code' => 'STD',
                    'board_basis' => null,
                    'eligibility' => 'public',
                    'pricing_strategy' => 'per_unit',
                    'is_refundable' => true,
                    'is_default' => true,
                    'is_active' => true,
                    'sort' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('rate_plans')->insert($rows);
        }

        $plans = DB::table('rate_plans')->where('is_default', true)->pluck('id', 'listing_id');

        // Only nights that said something. `whereNotNull('rate')` and friends
        // are the difference between carrying over a season and carrying over
        // every night a booking happened to touch.
        DB::table('room_type_calendar_days')
            ->join('room_types', 'room_types.id', '=', 'room_type_calendar_days.room_type_id')
            ->where(function ($query) {
                $query->whereNotNull('room_type_calendar_days.rate')
                    ->orWhereNotNull('room_type_calendar_days.min_stay')
                    ->orWhere('room_type_calendar_days.closed_to_arrival', true)
                    ->orWhere('room_type_calendar_days.closed_to_departure', true);
            })
            ->orderBy('room_type_calendar_days.id')
            ->select([
                'room_type_calendar_days.room_type_id',
                'room_type_calendar_days.date',
                'room_type_calendar_days.rate',
                'room_type_calendar_days.min_stay',
                'room_type_calendar_days.closed_to_arrival',
                'room_type_calendar_days.closed_to_departure',
                'room_types.listing_id',
            ])
            ->chunk(500, function ($days) use ($plans, $now) {
                $rows = [];

                foreach ($days as $day) {
                    $planId = $plans[$day->listing_id] ?? null;

                    if ($planId === null) {
                        continue;
                    }

                    $rows[] = [
                        'rate_plan_id' => $planId,
                        'room_type_id' => $day->room_type_id,
                        'date' => $day->date,
                        'rate' => $day->rate,
                        'min_stay' => $day->min_stay,
                        'closed_to_arrival' => $day->closed_to_arrival,
                        'closed_to_departure' => $day->closed_to_departure,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('rate_plan_days')->insert($rows);
                }
            });

        // Only once the copy is done. Postgres runs DDL inside the migration's
        // transaction, so a failure above leaves the old columns in place.
        Schema::table('room_type_calendar_days', function (Blueprint $table) {
            $table->dropColumn(['rate', 'min_stay', 'closed_to_arrival', 'closed_to_departure']);
        });
    }

    public function down(): void
    {
        Schema::table('room_type_calendar_days', function (Blueprint $table) {
            $table->decimal('rate', 10, 2)->nullable();
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
        });

        // Back from the default plans only. A property that has since built a
        // second rate plan cannot be squeezed back into one column, and
        // silently picking one of them would be worse than leaving it behind.
        DB::table('rate_plan_days')
            ->join('rate_plans', 'rate_plans.id', '=', 'rate_plan_days.rate_plan_id')
            ->where('rate_plans.is_default', true)
            ->orderBy('rate_plan_days.id')
            ->select([
                'rate_plan_days.room_type_id',
                'rate_plan_days.date',
                'rate_plan_days.rate',
                'rate_plan_days.min_stay',
                'rate_plan_days.closed_to_arrival',
                'rate_plan_days.closed_to_departure',
            ])
            ->chunk(500, function ($days) {
                foreach ($days as $day) {
                    DB::table('room_type_calendar_days')
                        ->where('room_type_id', $day->room_type_id)
                        ->whereDate('date', $day->date)
                        ->update([
                            'rate' => $day->rate,
                            'min_stay' => $day->min_stay,
                            'closed_to_arrival' => $day->closed_to_arrival,
                            'closed_to_departure' => $day->closed_to_departure,
                        ]);
                }
            });
    }
};
