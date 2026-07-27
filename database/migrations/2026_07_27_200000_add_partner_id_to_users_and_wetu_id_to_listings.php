<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete()->after('is_admin');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->string('wetu_id')->nullable()->after('partner_id')
                ->comment('Wetu property ID used for content sync');
            $table->timestamp('content_synced_at')->nullable()->after('wetu_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['wetu_id', 'content_synced_at']);
        });
    }
};
