<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered, typed pieces a page is made of.
 *
 * ## Why the type is a string and the payload is JSON
 *
 * Adding a block type must not require a migration — a new kind of block is a
 * class in App\Sites\Blocks and an entry in the registry, nothing more. A
 * Postgres enum or a column per block type would make every new block a schema
 * change on live customer content, which at this price is the difference
 * between a morning and a release.
 *
 * The trade is that the database cannot validate the payload. That is paid for
 * in App\Sites\Blocks\BlockDefinition: every type declares its own validation
 * rules and nothing writes a block without passing them, so the payload is
 * checked where its shape is actually known.
 *
 * ## What must never go in here
 *
 * No HTML from the customer, no script, no iframe, no third-party embed. The
 * limitedness of the kit is the security model — an owner who cannot introduce
 * code cannot introduce a vulnerability, and cannot break a page we host under
 * our own certificate. Rich text goes through the same purifier allow-list the
 * listing descriptions use (Listing::sanitizeRichText).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_page_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->jsonb('data')->default('{}');

            $table->unsignedInteger('sort')->default(0);

            // A block with nothing in it must not render as an empty band —
            // that is what makes a generated site look unfinished rather than
            // sparse. Generation switches off what it could not fill, so the
            // block is still there to be filled in rather than missing.
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->index(['site_page_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_blocks');
    }
};
