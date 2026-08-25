<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an opening time came from.
 *
 * `supply_points.opening_hours` is the one field on this table a traveller
 * acts on directly — they drive to it — and as of namibway:import-supply-hours
 * it can be filled by a machine from OpenStreetMap rather than typed by
 * somebody who phoned the place. Those two are not the same claim, and a
 * column that cannot tell them apart makes the content team re-check the ones
 * that were already checked and trust the ones that never were.
 *
 * So: the OSM element it was read from (`osm:node/1234567`), or null for a
 * value somebody entered by hand. It is deliberately the element id rather
 * than the word "osm" — that id is both the provenance and the way to look at
 * the source again, which is what a person re-checking this actually needs.
 *
 * `verified_at` keeps its own meaning and is untouched by the import: a
 * machine reading a third party's map is not somebody confirming the pumps
 * still work.
 *
 * Attribution: OpenStreetMap data is © OpenStreetMap contributors, ODbL. This
 * column is where an imported row says so about itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_points', function (Blueprint $table) {
            $table->string('opening_hours_source')->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('supply_points', function (Blueprint $table) {
            $table->dropColumn('opening_hours_source');
        });
    }
};
