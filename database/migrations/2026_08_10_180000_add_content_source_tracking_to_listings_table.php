<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Provenance for the two things a listing publishes: its text and its photos.
     *
     * photos_source already recorded where the LIVE photos came from. Two gaps
     * this closes:
     *
     *  - `pending_photos_source` — photos waiting in pending_image/pending_gallery
     *    had no provenance at all, so the approval step could not tell an owner's
     *    own photograph from a directory's. Now it can, and refuses the latter
     *    (see Listing::approvePendingPhotos()).
     *  - `description_source` — text had no provenance at all. Without it,
     *    "replace directory prose with copy we wrote" is not expressible, and
     *    every writer is stuck with fill-only-if-blank.
     *
     * Backfill is deliberately narrow: only listings whose description we know
     * came from a directory importer and that nobody has since rewritten.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('pending_photos_source')->nullable()->after('pending_gallery');
            $table->string('description_source')->nullable()->after('short_description');
        });

        // 'manual' predates App\Enums\ContentSource and means the same as 'partner'.
        // Left as-is rather than rewritten: the enum still accepts it, and a data
        // rewrite over live rows buys nothing here.
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['pending_photos_source', 'description_source']);
        });
    }
};
