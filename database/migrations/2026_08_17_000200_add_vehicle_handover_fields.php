<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle hand-over times — three sets of columns on three tables.
 *
 * ## Reservations: pickup_time and return_time
 *
 * The actual agreed hand-over times for this booking, stored as TIME strings
 * (HH:MM). Nullable — accommodation and activity reservations have none.
 * Set at booking time from the listing's defaults; the lodge can edit them
 * afterwards. Stored here rather than derived so a change to the listing's
 * defaults does not silently rewrite a confirmed booking.
 *
 * ## Bookable units: turnaround_days
 *
 * How many calendar days a vehicle needs between hand-over from one guest and
 * pickup by the next — cleaning, inspection, fuel. 0 is the default (same-day
 * turn is fine); 1 means a vehicle returned Monday is only available again
 * Wednesday. Accommodation has its own half-open check-in/check-out logic;
 * this is a vehicle-only concept.
 *
 * ## Listings: pickup_time and return_time defaults
 *
 * The rental company's standard opening and closing times, used to pre-fill
 * the booking form and to validate a requested time. TIME strings again.
 * Nullable — a listing that has not entered them leaves the form fields blank.
 *
 * See BOOKING_BEYOND_ROOMS.md §7.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->time('pickup_time')->nullable()->after('check_in');
            $table->time('return_time')->nullable()->after('check_out');
        });

        Schema::table('bookable_units', function (Blueprint $table): void {
            $table->unsignedTinyInteger('turnaround_days')->default(0)->after('total_units');
        });

        Schema::table('listings', function (Blueprint $table): void {
            $table->time('pickup_time')->nullable()->after('opening_hours');
            $table->time('return_time')->nullable()->after('pickup_time');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['pickup_time', 'return_time']);
        });

        Schema::table('bookable_units', function (Blueprint $table): void {
            $table->dropColumn('turnaround_days');
        });

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['pickup_time', 'return_time']);
        });
    }
};
