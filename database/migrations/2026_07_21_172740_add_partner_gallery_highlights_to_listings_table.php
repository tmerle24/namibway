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
            $table->foreignId('partner_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->json('gallery')->nullable()->after('image');
            $table->json('highlights')->nullable()->after('description');
            $table->boolean('accepts_inquiries')->default(true)->after('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['gallery', 'highlights', 'accepts_inquiries']);
        });
    }
};
