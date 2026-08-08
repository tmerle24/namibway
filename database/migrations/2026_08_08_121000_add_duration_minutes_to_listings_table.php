<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Typical duration of the experience, in minutes. Mainly for
            // activities and guided tours, where the traveler books a slot of a
            // given length (a 2h quad ride vs. a full-day excursion) — the trip
            // plan shows it next to the entry so a day's timing is judgeable
            // without opening the listing.
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('price_currency');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
