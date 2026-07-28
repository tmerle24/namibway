<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('contact_email');
            $table->string('address')->nullable()->after('phone');
            $table->string('scrape_source')->nullable()->after('address'); // ntb | namibiayp | namibiadirectory | manual
            $table->timestamp('scraped_at')->nullable()->after('scrape_source');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address', 'scrape_source', 'scraped_at']);
        });
    }
};
