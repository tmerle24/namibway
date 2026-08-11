<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's own pictures — copied bytes, never a reference.
 *
 * ## Why copied
 *
 * An imported photograph is written into the site's own prefix on the bucket
 * (sites/{slug}/…) rather than pointed at the listing's copy. Two reasons, and
 * the first is commercial: the customer is promised the content is theirs, and
 * a picture that vanishes when we delete a listing is not theirs. The second is
 * operational: `photos:audit-r2 --delete-orphaned` and every future cleanup
 * decides what is referenced by looking at listing columns, and a live customer
 * site whose images only exist in somebody else's row is one bad WHERE clause
 * away from going blank.
 *
 * The cost is duplicated storage. It is the right trade at this size, and
 * deduplicating by object would give back exactly the coupling we paid to
 * avoid.
 *
 * ## Images only
 *
 * Not "media". The kit accepts pictures and nothing else — no PDFs, no
 * arbitrary uploads — because an upload surface that accepts arbitrary files is
 * a hosting liability we would then have to police. The name says so.
 *
 * ## prospect_only, and what publishing does about it
 *
 * A draft built to win a customer may use photographs we may show but may not
 * hand over: Google Places imagery is publishable on namibway.com under
 * Google's terms and it expires (google_photos_expire_at), so it can sit on a
 * prospecting draft and can never sit on a site we have promised the customer
 * keeps. Marking it here rather than deciding at render time means publication
 * can be blocked on it, and the objects deleted, instead of the question being
 * asked once and forgotten.
 *
 * Directory imagery does not appear at all — it never gets imported, because
 * ContentSource::Directory is not publishable anywhere, draft included.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // A bucket key on the r2 disk, always — never a URL. A URL built
            // from mutable config is how the photo audit nearly deleted the
            // live library on 2026-08-09; a key survives the bucket moving
            // behind a custom domain.
            $table->string('key');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Alt text is not optional editorially — a picture with none is a
            // picture a blind customer of theirs cannot see — but it is
            // nullable here because generation genuinely cannot invent it and
            // an empty string would look answered.
            $table->string('alt')->nullable();

            $table->string('content_source')->nullable();
            $table->boolean('prospect_only')->default(false);
            $table->string('attribution')->nullable();

            $table->foreignId('source_listing_id')->nullable()->constrained('listings')->nullOnDelete();

            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['site_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_images');
    }
};
