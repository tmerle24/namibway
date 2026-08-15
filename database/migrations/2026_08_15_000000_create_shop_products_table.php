<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products a business sells or displays on its customer website.
 *
 * Products live on the site, not on the listing: a partner-only site (no
 * source_listing_id) is the primary use case — a boutique or craft shop that
 * has never listed on the travel platform. A listing-backed site can have a
 * shop too, but the products are the site's own, not derived from the listing.
 *
 * ## Categories
 *
 * `category` is a free string, not a foreign key. Two reasons: the set of
 * categories a shop uses is entirely the owner's choice, and a normalised table
 * would put a migration between "I have a new product type" and being able to
 * use it. Distinct values across the site's own products are what the filter
 * uses, so the catalogue defines its own taxonomy.
 *
 * ## Images
 *
 * `image_ids` stores an ordered array of SiteImage ids. The images themselves
 * stay in site_images — the same table the gallery and about blocks use — so
 * the site's image management is one place. A product that references a deleted
 * image gets a graceful null rather than a 500; the renderer drops missing ids.
 *
 * ## Price
 *
 * A free string. "N$ 350", "from N$ 1 200", "Call for price" — the owner knows
 * what they are selling and how they want to express it. Parsing currency would
 * add complexity without improving the page; a computed sort-by-price is a
 * later enhancement, if it ever matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug');

            $table->text('description')->nullable();
            $table->string('price')->nullable();
            $table->string('category')->nullable();

            // 'draft' | 'published' — only published products appear on the site.
            $table->string('status', 20)->default('draft');

            $table->json('image_ids')->default('[]');

            // Kept for traceability: lets the owner see which posts became
            // products without cross-referencing Instagram manually.
            $table->string('instagram_post_url')->nullable();

            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_products');
    }
};
