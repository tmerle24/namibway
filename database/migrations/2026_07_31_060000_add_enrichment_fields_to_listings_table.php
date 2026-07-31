<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('ntb_number')->nullable()->after('slug')->index();
            $table->json('short_description')->nullable()->after('description');
            $table->string('seo_description')->nullable()->after('short_description');
            $table->string('meta_title')->nullable()->after('seo_description');
            $table->json('facilities')->nullable()->after('address');
            $table->json('activities')->nullable()->after('facilities');
            $table->json('languages')->nullable()->after('activities');
            $table->json('opening_hours')->nullable()->after('languages');

            $table->unsignedTinyInteger('enrichment_score')->default(0)->after('opening_hours');
            $table->string('enrichment_status')->default('pending')->after('enrichment_score');
            $table->unsignedTinyInteger('confidence')->nullable()->after('enrichment_status');
            $table->string('data_source')->nullable()->after('confidence');
            $table->timestamp('verified_at')->nullable()->after('data_source');
            $table->timestamp('last_enriched_at')->nullable()->after('verified_at');

            $table->index('enrichment_score');
            $table->index('enrichment_status');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['enrichment_score']);
            $table->dropIndex(['enrichment_status']);

            $table->dropColumn([
                'ntb_number',
                'short_description',
                'seo_description',
                'meta_title',
                'facilities',
                'activities',
                'languages',
                'opening_hours',
                'enrichment_score',
                'enrichment_status',
                'confidence',
                'data_source',
                'verified_at',
                'last_enriched_at',
            ]);
        });
    }
};
