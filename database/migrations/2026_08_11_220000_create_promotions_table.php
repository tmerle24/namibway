<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An offer a property runs, applied to a finished price.
 *
 * Everything here is a *condition* or an *amount*. Nothing in this table can
 * change how a night is priced — that is the pricing strategy's job and a
 * promotion is not allowed to reach back into it. The chain is one-way:
 *
 *     rate → price → promotion → total
 *
 * ## Two windows, and they are not the same window
 *
 * `stay_*` is when the guest is there; `booked_*` is when they booked. An
 * early-bird offer is a wide stay window with a narrow booking window, and a
 * last-minute one is the reverse. Systems that model only one of them can
 * express neither.
 *
 * ## Scope
 *
 * Empty means "all of them". A promotion with no rate plans attached applies
 * to every rate plan, which is what a lodge means by "20% off in November".
 * Attaching some narrows it. The same for room types.
 *
 * ## The cap
 *
 * `max_uses` is enforced by a conditional UPDATE at booking time, in the same
 * transaction as the stay, exactly like an inventory counter — for the same
 * reason. Two people typing the last available code at once is the same race
 * as two people booking the last room, and PHP cannot referee it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Null is a public offer that applies by itself. A code has to be
            // typed, which is what makes it an agent or newsletter offer.
            $table->string('code', 40)->nullable();

            $table->string('discount_type', 30);
            $table->decimal('discount_value', 10, 2);

            $table->date('stay_from')->nullable();
            $table->date('stay_until')->nullable();
            $table->date('booked_from')->nullable();
            $table->date('booked_until')->nullable();

            $table->unsignedSmallInteger('min_nights')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One code per property, not one globally: two lodges may both run
            // "SUMMER26" and neither is wrong.
            $table->unique(['listing_id', 'code']);
            $table->index(['listing_id', 'is_active']);
        });

        Schema::create('promotion_rate_plan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_plan_id')->constrained()->cascadeOnDelete();

            $table->unique(['promotion_id', 'rate_plan_id']);
        });

        Schema::create('promotion_room_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();

            $table->unique(['promotion_id', 'room_type_id']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Null on delete, and the code and amount kept beside it: the
            // discount is frozen like the price it came off, so deleting a
            // finished offer must not make last month's bookings look
            // mispriced. Rule 4 covers a discount as much as a rate.
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code', 40)->nullable()->after('promotion_id');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('promotion_code');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn(['promotion_code', 'discount_amount']);
        });

        Schema::dropIfExists('promotion_room_type');
        Schema::dropIfExists('promotion_rate_plan');
        Schema::dropIfExists('promotions');
    }
};
