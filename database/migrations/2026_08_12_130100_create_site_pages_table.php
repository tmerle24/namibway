<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page of a customer website.
 *
 * Slice 1 generates exactly one page per site and the template is a one-pager,
 * which is the right shape for a business whose whole story fits on a phone
 * screen and whose customers are on a slow connection: one request, one paint.
 *
 * It is a table anyway, because the alternative — hanging blocks off the site
 * directly — makes a second page a data migration on live customer content
 * later. A row that never varies costs one join; retrofitting the row costs a
 * migration on everybody's site at once.
 *
 * `locale` is here for the same reason. The flyer sells "English and German,
 * further languages on request", and a German page is a second row under the
 * same site rather than a translated payload inside every block. That keeps
 * rendering a plain lookup and lets a page exist in one language without
 * forcing empty strings into the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Empty for the home page, so "/" and a named page are the same
            // lookup rather than a special case in the router.
            $table->string('slug')->default('');
            $table->string('locale', 5)->default('en');

            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();

            $table->boolean('is_home')->default(false);
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['site_id', 'locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');
    }
};
