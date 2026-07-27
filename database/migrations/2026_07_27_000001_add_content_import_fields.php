<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Tracks origin of scraped data (URL scraped from)
            $table->string('source_url')->nullable()->after('accepts_inquiries');
            // 'unclaimed' = scraped, not yet verified; 'pending' = owner contacted; 'claimed' = partner has taken ownership
            $table->string('claim_status')->default('unclaimed')->after('source_url');
            $table->string('website')->nullable()->after('claim_status');
            $table->string('contact_email')->nullable()->after('website');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('website');
            // Unique token sent in the "claim your listing" email
            $table->string('claim_token')->nullable()->unique()->after('source_url');
            $table->timestamp('claim_token_sent_at')->nullable()->after('claim_token');
            $table->timestamp('claimed_at')->nullable()->after('claim_token_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'claim_status', 'website', 'contact_email']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'claim_token', 'claim_token_sent_at', 'claimed_at']);
        });
    }
};
