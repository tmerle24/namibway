<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room type had no photo of its own, so the trip plan's room picker showed
 * the *property's* gallery next to every option — the same four pictures of the
 * lodge whether you picked the standard double or the family unit. Which room
 * you sleep in is most of what distinguishes the options, so it needs its own
 * pictures.
 *
 * Mirrors `listings.gallery`: an ordered array of storage paths (or absolute
 * URLs for scraper-sourced images), first one used as the thumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->json('gallery')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('gallery');
        });
    }
};
