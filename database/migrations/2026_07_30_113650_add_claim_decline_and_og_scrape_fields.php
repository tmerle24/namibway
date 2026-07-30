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
        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('claim_rejected_at')->nullable()->after('claimed_at');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('og_scraped_at')->nullable()->after('scraped_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('claim_rejected_at');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('og_scraped_at');
        });
    }
};
