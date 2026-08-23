<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thing you go and look at, as opposed to a place you are in.
 *
 * `cities` holds places — since 2026-08-18 including the parks, reserves and
 * landmarks a lodge can stand in. The line that table draws is written in
 * PlaceType::inDrivingMatrix(): a park is a row there *precisely because
 * something bookable stands on it*. That leaves the Hoba Meteorite, Lake
 * Otjikoto, the dinosaur footprints at Otjihaenamaparero, a hot spring and a
 * rock-art site with no lodge on them with nowhere to go — nothing is sold
 * there, so nothing puts them in `cities`, and nothing should.
 *
 * Hence a third noun beside the place and the business. Why not either:
 *
 *  - **Not another PlaceType.** A place is a container: it is a day's
 *    location, a node in the city-to-city driving matrix, an Explore filter
 *    value and the thing a listing is filed under. A meteorite is none of
 *    those, and adding it as a place would make it all four.
 *  - **Not a Listing.** The four listing types are things with a price and a
 *    partner, and the booking core reaches into that table — Explore, the room
 *    picker, availability, the inquiry flow. An attraction has no partner, no
 *    price and no availability. Same reasoning that keeps `menu_items` out of
 *    `bookable_units`: a small table at the edge the booking core never learns
 *    exists.
 *
 * It hangs off the existing geography rather than duplicating it. `city_id` is
 * the place it sits in or nearest to, and region and area are derived through
 * it exactly the way `Listing::getRegionAttribute()`/`getAreaAttribute()`
 * derive theirs — so this table adds no third location concept. The entries
 * that are *both* keep both rows and point at each other: Twyfelfontein,
 * Sossusvlei and Kolmanskop are already places because listings are filed
 * there, and are also things you drive to and walk around. The place row stays
 * the container; the row here carries what it costs, how long you need and how
 * you get in.
 *
 * Those operational columns are the point, and are why this is not a
 * duplicate of what a language model already knows. Encyclopedic facts about
 * the Hoba meteorite are free. Which track, whether it needs 4x4, whether a
 * permit is required, what it costs and when to go are not — and they are what
 * turns an answer into a day in a plan.
 *
 * Nullable on purpose, and null means "not established" rather than "no":
 * `requires_4x4 = false` is a claim somebody checked, `null` is a blank we
 * have not filled. Same care as the content-source ladder, where a null source
 * is untouchable rather than lowest-rank.
 *
 * Coordinates are nullable so entries can be seeded by name and geocoded
 * afterwards, as the tourism places were on 2026-08-18 — but an attraction
 * without them is not routable and cannot appear in a "what is near here / on
 * this road" answer, which is the whole reason for the table.
 *
 * Deliberately not here, so they are decided rather than defaulted: a curated
 * attraction↔listing relation (proximity is computed from coordinates first,
 * and a hand-made link should be an override on top of that, not the base
 * mechanism), and the pending_image/pending_gallery review queue listings
 * carry — the source columns are enough to enforce the publishing rule until
 * something actually fills this table from a third party.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attractions', function (Blueprint $table) {
            $table->id();

            // Translatable, so json rather than string — the same shape the
            // listing's name and description have carried since 2026-07-21.
            $table->json('name');
            $table->string('slug')->unique();
            $table->string('type');

            // Two texts with two jobs. `summary` is the one line that may be
            // handed to the model alongside a name and a coordinate;
            // `description` is what a detail page renders and must never enter
            // the itinerary prompt, which is capped for a reason
            // (MAX_CANDIDATES_PER_TYPE, and the OOM of 2026-08-09).
            $table->json('summary')->nullable();
            $table->json('description')->nullable();

            // The place it sits in or nearest to. Region and area are derived
            // through it; nothing is stored twice.
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();

            // How long to allow for it, in the same unit and shape as
            // listings.duration_minutes so a day plan can add the two up.
            $table->unsignedSmallInteger('visit_minutes')->nullable();

            // What it costs to get in. Null is "not recorded"; 0 is free, and
            // is a fact worth stating.
            $table->decimal('entry_fee', 10, 2)->nullable();
            $table->string('entry_fee_currency', 3)->default('NAD');

            // The operational truth. Nullable booleans: null is a blank, not a
            // "no" — see the class docblock.
            $table->boolean('requires_4x4')->nullable();
            $table->boolean('requires_permit')->nullable();
            $table->json('access_note')->nullable();
            $table->json('best_time_note')->nullable();

            $table->string('image')->nullable();
            $table->json('gallery')->nullable();

            // The content-source ladder applies here as everywhere else: text
            // and photographs taken from a third-party directory stay
            // ContentSource::directory and are not publishable.
            $table->string('description_source')->nullable();
            $table->string('photos_source')->nullable();
            $table->text('photos_attribution')->nullable();

            // Nothing auto-publishes, for the same reason the namibweb
            // importer creates listings unpublished.
            $table->boolean('is_published')->default(false);

            $table->timestamps();

            $table->index('type');
            $table->index('is_published');

            // The realistic query is a bounding box around a point or along a
            // route, narrowed in SQL before anything is loaded as a model and
            // long before anything reaches the model. Postgres has no PostGIS
            // here, so a btree over the pair is what makes that cheap.
            $table->index(['lat', 'lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attractions');
    }
};
