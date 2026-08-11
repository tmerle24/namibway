<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a region a country, so a listing has one: listings.city_id ->
 * cities.region_id -> regions.country_code. Currency, timezone, tax and
 * cancellation defaults resolve from there (App\Support\CountrySettings)
 * instead of from a single global constant.
 *
 * The country lives on the region rather than on the listing because a region
 * belongs to exactly one country and a listing inherits it — one place to be
 * right instead of one per listing. ISO 3166-1 alpha-2, no separate countries
 * table: the code is a stable natural key, and the per-country attributes are
 * config (config/region.php), which is the pattern CLAUDE.md already sets for
 * dial codes.
 *
 * The default is safe rather than a guess: every one of the 14 rows this
 * table holds is one of Namibia's political regions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->char('country_code', 2)->default('NA')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
