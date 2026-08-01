<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Distinct from google_photos_checked_at (which only tracks the standalone
            // photo job's own cache window). This is set whenever WebsiteFinderService
            // actually makes a real Places call for website/location facts — so a
            // business Google has no data for isn't re-queried on every single future
            // enrichment run, only once every enrichment.refresh_days.
            $table->timestamp('google_places_checked_at')->nullable()->after('google_photos_checked_at');
        });

        // Backfill: any listing that already completed a pipeline run recently did
        // attempt a Places lookup as part of it (unless it already had a website/all
        // location facts) — treating last_enriched_at as a same-day proxy avoids an
        // immediate full re-query of the ~7,000-listing backlog the moment this ships,
        // which is exactly the redundant spend this column exists to prevent. Listings
        // whose job timed out before ever finishing (last_enriched_at still null) are
        // correctly left unmarked — they never got a real attempt yet.
        DB::table('listings')
            ->whereNotNull('last_enriched_at')
            ->whereNull('google_places_checked_at')
            ->update(['google_places_checked_at' => DB::raw('last_enriched_at')]);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('google_places_checked_at');
        });
    }
};
