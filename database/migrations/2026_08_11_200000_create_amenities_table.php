<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a room has, from a catalogue everybody shares.
 *
 * **One catalogue, not one per property.** "En-suite bathroom" means the same
 * thing at every lodge in the country, and a shared vocabulary is the only
 * thing that makes two rooms comparable, a filter possible, or a channel
 * mapping cheap later. A property picks from the list; it does not write its
 * own. Anything genuinely missing is a request to the content team and one
 * more row here — which is a slower loop on purpose, because a catalogue
 * anybody can extend is a catalogue where "WiFi", "Wi-Fi" and "wifi" are three
 * different amenities within a month.
 *
 * A deliberate gap this leaves alone: `listings.facilities` is a free-text
 * array holding the *property's* amenities (pool, restaurant), populated by
 * the scrapers and searched by the traveller-facing keyword box. It is not
 * touched here. Moving it onto this catalogue is a sensible later step and a
 * bigger one — it reaches into the traveller site and into the enrichment
 * pipeline, and doing it badly would lose data the scrapers took months to
 * gather.
 *
 * The seed below is the catalogue as it stood on this date. Later additions
 * are later migrations, which is the price of it being data everyone shares
 * rather than a constant somebody edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->string('category', 40);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'sort']);
        });

        Schema::create('amenity_room_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['amenity_id', 'room_type_id']);
        });

        $now = now();
        $rows = [];
        $sort = 0;

        foreach ($this->catalogue() as $category => $names) {
            foreach ($names as $code => $name) {
                $sort += 10;
                $rows[] = [
                    'code' => $code,
                    'name' => $name,
                    'category' => $category,
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
        Schema::dropIfExists('amenity_room_type');
        Schema::dropIfExists('amenities');
    }

    /**
     * The starting catalogue, written from Namibian lodge rate sheets rather
     * than from a generic hotel list. "Generator hours only" and "No power
     * after dark" are in here because they are ordinary here and because a
     * guest who is not told arrives with a dead camera battery.
     *
     * @return array<string, array<string, string>>
     */
    private function catalogue(): array
    {
        return [
            'beds' => [
                'king_bed' => 'King bed',
                'twin_beds' => 'Twin beds available',
                'extra_bed' => 'Extra bed possible',
                'cot' => 'Cot available',
                'mosquito_nets' => 'Mosquito nets',
            ],
            'bathroom' => [
                'ensuite_bathroom' => 'En-suite bathroom',
                'shared_bathroom' => 'Shared bathroom',
                'outdoor_shower' => 'Outdoor shower',
                'bath' => 'Bath',
                'shower_only' => 'Shower only',
                'hot_water' => 'Hot water',
                'hairdryer' => 'Hairdryer',
            ],
            'comfort' => [
                'air_conditioning' => 'Air conditioning',
                'ceiling_fan' => 'Ceiling fan',
                'heater' => 'Heater',
                'fireplace' => 'Fireplace',
                'safe' => 'Safe',
                'desk' => 'Desk',
                'seating_area' => 'Seating area',
            ],
            'outdoors' => [
                'private_deck' => 'Private deck',
                'plunge_pool' => 'Private plunge pool',
                'sala' => 'Shaded sala or loungers',
                'braai' => 'Braai facilities',
                'waterhole_view' => 'View over a waterhole',
                'star_bed' => 'Star bed',
                'private_garden' => 'Private garden',
            ],
            'food_and_drink' => [
                'tea_coffee' => 'Tea and coffee station',
                'minibar' => 'Minibar',
                'fridge' => 'Fridge',
                'kitchenette' => 'Kitchenette',
                'self_catering_kitchen' => 'Full self-catering kitchen',
            ],
            'power' => [
                'wifi' => 'Wi-Fi in the room',
                'charging_points' => 'Charging points (220V)',
                'solar_power' => 'Solar power',
                'generator_hours' => 'Generator hours only',
                'no_power_at_night' => 'No power after dark',
                'room_telephone' => 'Room telephone',
            ],
            'services' => [
                'daily_housekeeping' => 'Daily housekeeping',
                'laundry_service' => 'Laundry service',
                'room_service' => 'Room service',
                'turndown_service' => 'Turndown service',
            ],
            'accessibility' => [
                'wheelchair_accessible' => 'Wheelchair accessible',
                'ground_floor' => 'Ground floor',
                'no_steps' => 'No steps to the room',
            ],
        ];
    }
};
