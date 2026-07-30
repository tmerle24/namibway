<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'scrape_source')) {
                $table->string('scrape_source')->nullable()->after('accepts_inquiries');
            }
            if (! Schema::hasColumn('listings', 'scrape_id')) {
                $table->string('scrape_id')->nullable()->after('scrape_source');
            }
            if (! Schema::hasColumn('listings', 'scrape_data')) {
                $table->json('scrape_data')->nullable()->after('scrape_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['scrape_source', 'scrape_id', 'scrape_data']);
        });
    }
};
