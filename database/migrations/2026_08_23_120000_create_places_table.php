<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tourism location, as opposed to an address.
 *
 * On 2026-08-18 parks, reserves and landmarks were given rows in `cities` so a
 * lodge standing in one had somewhere to be filed. That solved the filing
 * problem by breaking the noun: a table called cities held Etosha National
 * Park, `population` and `area_km2` were meaningless on those rows, and the
 * question a listing actually has to answer twice — *what is your address* and
 * *where do you sit for a traveler* — could only be answered once.
 *
 * So the two are separated:
 *
 *  - `cities` is the address. Windhoek, Solitaire, Sesriem — an administrative
 *    gazetteer, classified by CityType, and what a listing's street address
 *    resolves to. Every listing keeps its `city_id`.
 *  - `places` is where a traveler goes. Etosha National Park, Onguma, Sossusvlei
 *    — classified by PlaceType, and what the trip plan is built out of.
 *
 * A listing may have both, and the two are not the same answer: a camp on
 * Onguma has a postal address in Outjo and sits, for anyone planning a trip, in
 * Etosha.
 *
 * This is step 1 of 3 and changes no behaviour. The tourism rows are **copied**
 * out of `cities` rather than moved, `legacy_city_id` records where each came
 * from, and `listings.place_id` is filled from the copy. Everything still reads
 * `cities` exactly as before. Step 2 repoints the readers; step 3 deletes the
 * tourism rows from `cities`, drops `legacy_city_id` and narrows CityType.
 */
return new class extends Migration
{
    /** The CityType values that are tourism locations rather than settlements. */
    private const TOURISM_TYPES = ['national_park', 'nature_reserve', 'landmark'];

    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug')->unique();
            $table->string('type');

            $table->foreignId('region_id')->constrained()->restrictOnDelete();

            // The area a traveler names — "Etosha" over Etosha National Park,
            // Onguma and Ongava. Same relation `cities` gained on 2026-08-19.
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();

            // The nearest town, where there is one. A place is not *in* a city
            // — this is the fallback for an address and for "what is near
            // here", nothing more, which is why it is nullable and why no
            // address column lives on this table.
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();

            $table->string('image')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();

            // Where this row came from, for the two steps that follow. Dropped
            // in step 3, so nothing outside this migration series may read it.
            $table->foreignId('legacy_city_id')->nullable()->constrained('cities')->nullOnDelete();

            $table->timestamps();

            $table->index('type');
        });

        $tourismCities = DB::table('cities')->whereIn('type', self::TOURISM_TYPES)->get();
        $now = now();

        foreach ($tourismCities as $city) {
            DB::table('places')->insert([
                // `cities.name` is a plain string; `places.name` is
                // translatable, so it is json like every other translated
                // column in this codebase.
                'name' => json_encode(['en' => $city->name], JSON_THROW_ON_ERROR),
                'slug' => $city->slug,
                'type' => $city->type,
                'region_id' => $city->region_id,
                'destination_id' => $city->destination_id,
                'city_id' => null,
                'image' => $city->image,
                'lat' => $city->lat,
                'lng' => $city->lng,
                'legacy_city_id' => $city->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });

        // A listing filed in a tourism "city" is a listing whose place we know
        // and whose address we do not. city_id is deliberately left as it is
        // for now — step 3 clears it, once nothing reads it for routing.
        DB::statement('
            UPDATE listings SET place_id = places.id
            FROM places
            WHERE places.legacy_city_id = listings.city_id
        ');

        Schema::table('attractions', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->after('city_id')->constrained()->nullOnDelete();
        });

        // The place a city belongs to, so that everything Kaia routes on is a
        // place and the driving matrix stays place-to-place rather than
        // becoming a polymorphic city-or-place pair. Nullable: most of the ~105
        // gazetteer rows are villages no traveler ever stays in. Filled in
        // step 2, where the trip-relevant towns get their place rows.
        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()->after('destination_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
        });

        Schema::table('attractions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('place_id');
        });

        Schema::dropIfExists('places');
    }
};
