<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lines of an order: what was ordered, how many, and what it cost when it
 * was ordered.
 *
 * ## Frozen, like every other result in this system
 *
 * `name`, `unit_price` and `currency` are copied off the menu item at the
 * moment the order is placed and never read from it again. This is the same
 * rule `reservation_nights` follows and the same reason: a restaurant that puts
 * its prices up next week must not change what a guest ordered last week, and
 * an order whose total is recomputed on every read is a total nobody can stand
 * behind. `menu_item_id` is kept beside the frozen values so "how often is this
 * ordered?" stays answerable — it is a reference, not the source of truth, and
 * it goes null rather than taking the order with it when a dish is deleted.
 *
 * `line_total` is stored rather than derived for the same reason the price on a
 * reservation is stored: it is the number that was shown and agreed to. It is
 * quantity × unit_price at write time, and nothing recomputes it afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->char('currency', 3)->default('NAD');

            $table->timestamps();

            $table->index('inquiry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_items');
    }
};
