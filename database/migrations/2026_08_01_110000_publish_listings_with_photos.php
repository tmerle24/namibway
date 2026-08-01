<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix so the public site has something to show: publish every
 * listing that already has a real photo (image or gallery) but was left
 * unpublished, e.g. imported/scraped listings still awaiting manual review.
 * Listings without any photo are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('is_published', false)
            ->where(function ($query) {
                $query->whereNotNull('image')
                    ->orWhereRaw("json_array_length(COALESCE(gallery, '[]')) > 0");
            })
            ->update(['is_published' => true]);
    }

    public function down(): void
    {
        // Not reversible: which rows were already published vs. flipped by this
        // migration isn't recorded anywhere.
    }
};
