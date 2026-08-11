<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who counts as what, per property.
 *
 * Age bands are the property's to decide and they genuinely differ — one lodge
 * charges a child from 3 to 11, the next from 2 to 15, and a third has two
 * child bands. So this is data, not an enum, and the boundaries live here
 * rather than in the pricing code.
 *
 * `charge_percent` is the share of the adult amount somebody in this category
 * pays. It is how every rate sheet in the market states it ("children under 12
 * at 50%"), and it keeps a child's price following the adult price through
 * every season without a second set of numbers to maintain.
 *
 * `counts_toward_occupancy` is the other half of the question, and it is
 * separate on purpose: a lodge with a 1000 / 1300 / 1500 tariff by number of
 * guests means the third person, child or not, moves the room to 1500 — while
 * an infant in a cot does not. Guessing that from the kind would be wrong for
 * one of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('kind', 20);

            // Both ends optional: "12 and over" has no upper bound, and an
            // infant band starts at nought.
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();

            $table->decimal('charge_percent', 5, 2)->default(100);
            $table->boolean('counts_toward_occupancy')->default(true);

            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['listing_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_categories');
    }
};
