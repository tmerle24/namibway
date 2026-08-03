<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the free-text Listing::region string with a proper city_id FK.
 * Backfill is a rough default, not a claim of accuracy — many listings
 * (lodges near a park) don't actually sit "in" any town — one representative
 * city per political region is picked below so nothing is left unset; an
 * admin should review/reassign individual listings afterward via the new
 * City picker in Filament. Low risk: only 23 listings exist at the time of
 * writing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('region')->constrained()->nullOnDelete();
        });

        $representativeCity = [
            'Khomas' => 'windhoek',
            'Erongo' => 'swakopmund',
            'Hardap' => 'mariental',
            'Karas' => 'keetmanshoop',
            'Kunene' => 'outjo',
            'Otjozondjupa' => 'otjiwarongo',
        ];

        $cityIds = DB::table('cities')->whereIn('slug', $representativeCity)->pluck('id', 'slug');

        foreach ($representativeCity as $region => $citySlug) {
            DB::table('listings')
                ->where('region', $region)
                ->update(['city_id' => $cityIds[$citySlug]]);
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('region')->nullable()->after('city_id');
        });

        DB::table('listings')->update([
            'region' => DB::raw('(select regions.name from cities inner join regions on regions.id = cities.region_id where cities.id = listings.city_id)'),
        ]);

        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
