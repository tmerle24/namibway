<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business's own mark, at the top of its own website.
 *
 * A plain R2 key rather than a row in `site_images`: a logo is a property of
 * the site, not a picture a block points at, and it is the one image here that
 * is never generated, never imported and never part of the content ladder —
 * it comes from the owner, or it is absent and the name is set in type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('logo_key')->nullable()->after('accent');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('logo_key');
        });
    }
};
