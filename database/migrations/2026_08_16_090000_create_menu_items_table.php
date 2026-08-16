<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a restaurant sells: one row per dish, drink or set menu.
 *
 * ## Why not `bookable_units`
 *
 * A room type is a thing with a *count* — total_units, a calendar day, a
 * conditional UPDATE on a counter. A plate of kudu steak has none of that: the
 * kitchen decides whether it can cook it, and no system of ours holds a number
 * to decrement. Putting menu items in `bookable_units` would mean inventing a
 * unit count for something that has none, and then teaching the inventory
 * writer to ignore it — the exact "flag for every vertical" failure
 * `BOOKING_BEYOND_ROOMS.md` §6 warns against. So this is a separate, small
 * table at the edge, and the booking core never learns it exists.
 *
 * ## Why the price is a decimal here and a string on the website builder
 *
 * `App\Sites\Blocks\PriceListBlock` stores prices as free text ("from N$ 850")
 * because a price list is typed by a person and only ever read by one. These
 * are added up into an order total and sent to a kitchen, so they have to be
 * numbers. A "market price" item is expressed by taking it off the menu for
 * ordering, not by putting prose in a decimal column.
 *
 * ## Category
 *
 * A free string, for the same reason `shop_products.category` is one: the
 * sections a menu uses are the owner's choice, and a normalised table would put
 * a migration between "we have a specials board now" and being able to use it.
 * `sort` orders items within a section; the sections themselves come out in the
 * order their first item does, so a menu reads top to bottom the way it is
 * written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // e.g. "Starters", "Mains", "Drinks". Null groups under a generic
            // heading rather than being hidden — an unfiled dish is still on
            // sale.
            $table->string('category')->nullable();

            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('NAD');

            // A dish, plated. Optional and singular: a menu is a list, and a
            // gallery per line would make it a catalogue.
            $table->string('image')->nullable();

            // Off the menu without losing it — the seasonal dish that comes
            // back, the one the kitchen has run out of this week.
            $table->boolean('is_available')->default(true);

            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['listing_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
