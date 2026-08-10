<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // What kind of vehicle this is — see App\Enums\VehicleClass.
            //
            // Left null on every existing row rather than backfilled from the
            // `highlights` heuristic the trip plan used until now ("does the
            // array contain the string 'Camper'"). That heuristic is exactly
            // what this column replaces: it cannot separate a rooftop-tent 4x4
            // from a motorhome, or a sedan from a 4x4, so backfilling from it
            // would write a confident guess into a column whose whole point is
            // to be more precise than the guess — and nothing downstream could
            // then tell the two apart. Null reads as "not recorded", and the
            // candidate filter falls back to the old heuristic for such rows,
            // so an unfilled catalog behaves exactly as it did before.
            $table->string('vehicle_class')->nullable()->after('vehicle_category');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('vehicle_class');
        });
    }
};
