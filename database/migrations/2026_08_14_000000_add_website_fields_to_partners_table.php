<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website-builder fields on the partner record.
 *
 * A partner without a listing (shop, boutique, service business) can have a
 * site generated directly from here. The same field set as a listing, minus
 * the booking-system columns: a short description for the hero, a hero
 * photograph, a gallery, an address with coordinates, and the business type
 * so the generator does not have to ask for it every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('short_description')->nullable()->after('bio');
            $table->string('address')->nullable()->after('short_description');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            // Hero photograph — a bucket key, same convention as listings.image.
            $table->string('image')->nullable()->after('logo');
            // Gallery — ordered array of bucket keys, same as listings.gallery.
            $table->json('gallery')->nullable()->after('image');
            // BusinessType value; stored so the generator does not have to
            // ask each time it rebuilds and so the site type can be found
            // without loading the site.
            $table->string('business_type')->nullable()->after('gallery');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'address',
                'latitude',
                'longitude',
                'image',
                'gallery',
                'business_type',
            ]);
        });
    }
};
