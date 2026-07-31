<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Recorded whenever a publish action succeeds — the explicit Terms &
            // Conditions / "I have the right to publish this content" consent required
            // by the publish confirmation modal. 'admin' or 'owner' (via claim_token).
            $table->timestamp('terms_accepted_at')->nullable()->after('photos_approved_at');
            $table->string('terms_accepted_by')->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_accepted_by']);
        });
    }
};
