<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activates every remaining unpublished listing, including those without a
 * photo. Previously only listings with a real image were auto-published (see
 * publish_listings_with_photos); the frontend now has per-category
 * placeholder images (ExploreSection/ListingDetail), so a missing photo no
 * longer needs to block a listing from going live.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('is_published', false)
            ->update(['is_published' => true]);
    }

    public function down(): void
    {
        // Not reversible: which rows were already published vs. flipped by this
        // migration isn't recorded anywhere.
    }
};
