<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan items as rows — the data layer that makes plan entries bookable.
 *
 * Today a plan is one JSON column. That is fine for planning: Claude writes it,
 * the traveler edits it, and nothing else needs to look inside it. But a booking
 * has side-effects JSON cannot carry:
 *
 *  - `inquiry_id` is a real foreign key. A JSON blob cannot follow a deletion.
 *  - "Which plans have unbooked items?" is a query over rows, not documents.
 *  - The collaborative direction (comments, change attribution) needs items for
 *    an author and a note to hang off.
 *
 * Decision from BOOKING_BEYOND_ROOMS.md § 7.5: JSON stays for planning; rows
 * arrive when a plan item becomes bookable. An item row is created at the moment
 * a booking request is sent, carries the inquiry it produced, and is updated
 * when the plan later changes or the inquiry reaches a terminal status.
 *
 * `inquiry_id` is UNIQUE: one booking per item. A declined or expired inquiry
 * stays on the item; re-booking mints a new item row (the old one's history is
 * useful, not garbage — a "previously unavailable" label is honest).
 *
 * `listing_id` goes NULL on listing deletion rather than cascading: the item is
 * the record of the booking, not a dangling pointer — it should survive a listing
 * being unpublished or merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('saved_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();

            // ListingType values: accommodation / activity / restaurant / vehicle.
            // Stored as a plain string so a future vertical does not require a
            // schema change to appear here.
            $table->string('kind', 32);

            // Stay dates for accommodation (check_in / check_out); activity date
            // for one-day items; null until we know them.
            $table->date('date')->nullable();
            $table->date('date_to')->nullable();

            // Set once the booking request is sent; goes null if the inquiry is
            // deleted (rare — inquiries are soft-deleted by status change, not
            // removed). UNIQUE enforces one booking per item.
            $table->foreignId('inquiry_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Position within the plan (matches the day sort in plan_json).
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['saved_plan_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
    }
};
