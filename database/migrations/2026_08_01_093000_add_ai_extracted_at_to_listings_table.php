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
            // Same reasoning as google_places_checked_at: ai_extract can genuinely find
            // nothing on a page (no facilities/activities/opening-hours info to extract),
            // and unlike description generation that outcome never self-resolves — the
            // fields just stay empty, the score stays low, and the listing keeps getting
            // picked for every future enrichment run, re-asking Claude the same question
            // forever. This caps how often that's retried.
            $table->timestamp('ai_extracted_at')->nullable()->after('google_places_checked_at');
        });

        // Same backfill rationale as google_places_checked_at, but narrower: ai_extract
        // only ever runs when a website was actually fetched, so — unlike the Places
        // step, which runs regardless — a listing with no website never got a real
        // extraction attempt no matter how many times last_enriched_at was stamped.
        // Backfilling those too would wrongly block their first real attempt for up to
        // refresh_days once they do get a website. Restrict to listings that already
        // have one.
        DB::table('listings')
            ->whereNotNull('last_enriched_at')
            ->whereNotNull('website')
            ->whereNull('ai_extracted_at')
            ->update(['ai_extracted_at' => DB::raw('last_enriched_at')]);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('ai_extracted_at');
        });
    }
};
