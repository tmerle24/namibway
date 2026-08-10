<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social/further links per listing, keyed by platform
     * (facebook, instagram, youtube, tripadvisor, …).
     *
     * Partner already carries a single instagram/facebook pair, but that is the
     * *company's* presence and a partner can hold several listings — a lodge and
     * its sister camp have their own pages. Directory sources (namibweb.com first
     * among them) publish those per property, so they belong on the listing.
     * Keyed JSON rather than columns because the set of platforms is open-ended.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->json('social_links')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('social_links');
        });
    }
};
