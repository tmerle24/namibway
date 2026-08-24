<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where you can fill up, and where you can buy food for three nights.
 *
 * The trip plan learned on 2026-08-24 to say what stands beside the road
 * between two stages (`attractions`, and App\Services\Routing\RouteStopFinder).
 * The other half of a long Namibian leg is not what is worth seeing on it but
 * what you need to have with you before you start it: the last pump before
 * 250 km of gravel, the last supermarket before a self-catering camp with no
 * restaurant and no shop.
 *
 * Why this is not a row in `attractions`, having just built that table:
 *
 *  - **Nobody goes there.** An attraction is measured by whether it is worth
 *    a detour; a filling station is measured by whether it is the last one.
 *    That is a relation to the *road ahead* rather than a position on the leg,
 *    which is why the finder needs the whole route to answer it at all and why
 *    the same station is worth naming on one trip and not on the next.
 *  - **Different columns, and they are the entire point.** Opening hours and
 *    which pump it has decide whether the stop is any use; visit_minutes,
 *    entry_fee, requires_permit and a photo gallery are meaningless here.
 *    Bolting both sets onto one table would mean every row carrying the other
 *    noun's blanks.
 *  - **Different content economics.** An attraction is written once and stays
 *    true for a decade. A filling station closes, runs dry, or changes hands,
 *    which is what `verified_at` is for: null means nobody has checked, and a
 *    date is somebody's word that they did.
 *
 * Same reasoning that keeps `menu_items` out of `bookable_units` and
 * `attractions` out of `places` — a small table at the edge that the booking
 * core never learns exists.
 *
 * It hangs off the existing geography rather than inventing a third one:
 * `city_id` for the town it is in, `place_id` for the park or reserve where
 * there is no town (Okaukuejo sells fuel and is not in one). Coordinates are
 * what actually locate it, and are nullable so a row can be entered by name
 * and located afterwards — a supply point in a town is at the town until
 * somebody sharpens it, which for a rule that measures gaps in hundreds of
 * kilometres is precise enough. Without any coordinates it cannot be found on
 * a route at all, which the admin table says in as many words.
 *
 * Deliberately absent: photographs and long prose. There is nothing to look
 * at, so there is nothing for the content-source ladder to protect — the one
 * `note` column is ours, written by whoever verified the row ("cash only",
 * "diesel often out"). A row here is a fact, not a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_points', function (Blueprint $table) {
            $table->id();

            // A proper noun — "Solitaire", "Engen Kamanjab" — so a plain
            // string rather than the translatable json a listing's name
            // carries. Nobody translates the name of a filling station.
            $table->string('name');
            $table->string('slug')->unique();

            // What you can get here: App\Enums\SupplyService values, and the
            // only classification this table has. See that enum for why there
            // is no `type` column beside it.
            $table->json('services');

            // Which pump, where there is one. Empty means "not recorded", not
            // "neither" — the same rule as the attractions table's nullable
            // booleans.
            $table->json('fuel_types')->nullable();

            // OpenStreetMap `opening_hours` syntax, verbatim, because every
            // source this will be filled from already speaks it. Parsed by
            // App\Support\OpeningHours, which understands a documented subset
            // and refuses the rest rather than half-reading it.
            $table->string('opening_hours')->nullable();

            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();

            // One line from whoever checked the row. Translatable because it
            // is advice a traveller reads, unlike the name.
            $table->json('note')->nullable();

            // When a human last confirmed this still exists and still sells
            // what it says. Null is "nobody has checked", which is the honest
            // state of everything seeded from knowledge rather than from a
            // visit.
            $table->timestamp('verified_at')->nullable();

            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index('is_published');

            // The only query this table has: a bounding box around a route.
            // No PostGIS here, so a btree over the pair is what makes it cheap.
            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_points');
    }
};
