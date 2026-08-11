<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same catalogue, for the property as well as the room.
 *
 * A pool belongs to the lodge and a mosquito net to the chalet, but "Wi-Fi"
 * and "Wheelchair accessible" are asked about at both levels and must not
 * become two entries that drift apart. So one vocabulary with a scope, rather
 * than two catalogues: `room`, `property`, or `both`.
 *
 * ## What this replaces
 *
 * `listings.facilities` is a free-text array the scrapers and the AI extractor
 * fill — eleven known keys from namibweb plus whatever a website yielded. It
 * stays exactly where it is, and the rule between the two is the one already
 * written down for descriptions and photos in CLAUDE.md's content-source
 * ladder, applied to amenities:
 *
 * **Once a property has structured amenities, the free text is ignored.**
 *
 * Not deleted — ignored. A scraped guess is worth keeping as a record of what
 * a directory said, in the same way `scrape_data` is; it is simply no longer
 * the answer once somebody who owns the property has given one. A listing with
 * no structured amenities still reads from `facilities`, so nothing goes blank
 * on the way.
 *
 * `amenities:backfill-listings` maps the free text onto this catalogue where
 * it can. It is a command with a dry run rather than something this migration
 * does, because bulk-writing over existing rows is exactly the class of
 * operation that cost this project a day once already — see CLAUDE.md,
 * "Data-loss lesson".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->string('scope', 20)->default('room')->after('category');
        });

        Schema::create('amenity_listing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['amenity_id', 'listing_id']);
        });

        // Asked about at both levels. A guest wants to know whether the room
        // has Wi-Fi *and* whether the lodge does, and they are often different
        // answers here.
        DB::table('amenities')
            ->whereIn('code', ['wifi', 'wheelchair_accessible', 'laundry_service', 'air_conditioning'])
            ->update(['scope' => 'both']);

        $now = now();
        $rows = [];
        $sort = 0;

        foreach ($this->propertyCatalogue() as $category => $names) {
            foreach ($names as $code => $name) {
                $sort += 10;
                $rows[] = [
                    'code' => $code,
                    'name' => $name,
                    'category' => $category,
                    'scope' => 'property',
                    'sort' => $sort,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('amenities')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_listing');

        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }

    /**
     * What a Namibian lodge lists about itself rather than about a room. The
     * eleven keys the namibweb scraper produces are all in here by design —
     * that mapping is the whole point of the backfill command.
     *
     * @return array<string, array<string, string>>
     */
    private function propertyCatalogue(): array
    {
        return [
            'comfort' => [
                'swimming_pool' => 'Swimming pool',
                'spa' => 'Spa or wellness treatments',
                'lounge' => 'Guest lounge',
                'library' => 'Library',
                'curio_shop' => 'Curio shop',
                'conference_facilities' => 'Conference facilities',
            ],
            'food_and_drink' => [
                'restaurant' => 'Restaurant',
                'bar' => 'Bar',
                'breakfast_served' => 'Breakfast served',
                'packed_lunches' => 'Packed lunches',
                'special_diets' => 'Special diets catered for',
            ],
            'outdoors' => [
                'game_drives' => 'Guided game drives',
                'guided_walks' => 'Guided walks',
                'hide_or_waterhole' => 'Hide or floodlit waterhole',
                'camping_area' => 'Camping area',
                'boma' => 'Boma or fire pit',
            ],
            'power' => [
                'property_wifi' => 'Wi-Fi in public areas',
                'mobile_reception' => 'Mobile reception',
            ],
            'services' => [
                'airstrip' => 'Airstrip',
                'secure_parking' => 'Secure parking',
                'fuel' => 'Fuel available',
                'credit_cards' => 'Credit cards accepted',
                'pets_allowed' => 'Pets allowed',
                'children_welcome' => 'Children welcome',
                'transfers' => 'Airport or town transfers',
            ],
        ];
    }
};
