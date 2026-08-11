<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product a property sells: what it includes at the table, who may book it,
 * how it turns a night into an amount.
 *
 * Rate plans are the industry's own vocabulary — ARI, the OTA schema and every
 * channel manager have them — which is why they are here rather than something
 * of our own invention. See BOOKING_SYSTEM.md.
 *
 * Two things are deliberately *not* columns:
 *
 * - **Board is not a price dimension**, it is this. A lodge sells a B&B rate
 *   and a DBB rate, not a room plus a dinner, so the two are two rows here.
 * - **Residency is not a guest discount**, it is this. NWR publishes
 *   Namibian-resident, SADC and international prices side by side; those are
 *   three rows here, and modelling them as a discount on a guest cannot
 *   represent them.
 *
 * A property with no rate plan at all keeps working: the rate then falls back
 * to the room type's own `rate_per_night`, exactly as it did before this
 * table existed. Absence is a valid state, not a gap to repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();

            // Nullable, meaning "not stated". Rates that existed before rate
            // plans did are carried over into a default plan, and nobody knows
            // whether they included breakfast — asserting one would be
            // inventing a claim about somebody's product. The form requires it
            // for anything created from here on.
            $table->string('board_basis')->nullable();

            // 'public' is safe as a default in a way board basis is not: a
            // rate nobody restricted is a rate anybody may book.
            $table->string('eligibility')->default('public');
            $table->string('pricing_strategy')->default('per_unit');

            // Nights before arrival inside which cancelling carries a penalty.
            // Null means "use the property's country default" — see
            // App\Support\CountrySettings — so a plan says nothing unless it
            // actually differs.
            $table->unsignedSmallInteger('cancellation_days')->nullable();
            $table->boolean('is_refundable')->default(true);

            // Exactly one default per listing, enforced below. The default is
            // what every existing screen and every existing booking path uses
            // when nobody has chosen a plan, which is what lets rate plans
            // arrive without anything changing on screen.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['listing_id', 'code']);
            $table->index(['listing_id', 'is_active']);
        });

        // A partial unique index rather than a plain one: only the rows that
        // claim to be the default take part, so a listing can have many plans
        // and never two defaults. Postgres is the production and CI database;
        // SQLite understands the same syntax, which is what the test suite
        // runs on.
        Schema::getConnection()->statement(
            'CREATE UNIQUE INDEX rate_plans_one_default_per_listing ON rate_plans (listing_id) WHERE is_default'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plans');
    }
};
