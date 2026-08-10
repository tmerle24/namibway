<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // What `price_from` is quoted per — see App\Enums\PriceUnit.
            //
            // Deliberately left null on every existing row instead of being
            // backfilled from the listing type. "Accommodation = per night per
            // room" looks like a safe guess and isn't: per-person-sharing rates
            // are the norm in this market, so a blanket backfill would stamp a
            // confident, frequently wrong claim onto thousands of scraped rows —
            // and nothing downstream could tell the guess apart from a rate a
            // partner actually confirmed. Null reads as "not recorded", which is
            // the truth, and the plan keeps counting such a price exactly as it
            // did before this column existed.
            $table->string('price_unit')->nullable()->after('price_currency');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('price_unit');
        });
    }
};
