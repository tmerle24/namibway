<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('enriched_by')->nullable()->after('data_source');
        });

        Schema::table('enrichment_jobs', function (Blueprint $table) {
            $table->string('actor')->nullable()->after('source');
            $table->json('fields_changed')->nullable()->after('log');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('enriched_by');
        });

        Schema::table('enrichment_jobs', function (Blueprint $table) {
            $table->dropColumn(['actor', 'fields_changed']);
        });
    }
};
