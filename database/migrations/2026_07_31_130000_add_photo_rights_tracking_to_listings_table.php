<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Website-scraped photos are staged here instead of going straight to the
            // live image/gallery columns — we don't have the owner's permission to
            // publish their own marketing photos until they (or an admin) approve them.
            $table->string('pending_image')->nullable()->after('gallery');
            $table->json('pending_gallery')->nullable()->after('pending_image');

            // Where the LIVE image/gallery actually came from, for attribution and for
            // the Google Places caching-compliance sweep (see google_photos_expire_at).
            $table->string('photos_source')->nullable()->after('pending_gallery');
            $table->text('photos_attribution')->nullable()->after('photos_source');
            $table->timestamp('photos_approved_at')->nullable()->after('photos_attribution');
            $table->timestamp('google_photos_expire_at')->nullable()->after('photos_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'pending_image',
                'pending_gallery',
                'photos_source',
                'photos_attribution',
                'photos_approved_at',
                'google_photos_expire_at',
            ]);
        });
    }
};
