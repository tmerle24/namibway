<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal foot of a customer website, and the moment it was agreed to.
 *
 * We write the first version — a page with nothing behind Privacy is not a
 * page anybody can publish — but the business is who it belongs to and who is
 * liable for it, so all three are editable and none of them is generated
 * wording pretending to be advice.
 *
 * `terms_accepted_at` is the other half: publishing under somebody's name is
 * the point at which they have to have seen this, and accepting it is also how
 * they accept ours. A timestamp and a name, because "who agreed and when" is
 * the only thing worth being able to answer later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('legal_privacy')->nullable()->after('social_links');
            $table->text('legal_imprint')->nullable()->after('legal_privacy');
            $table->string('legal_copyright')->nullable()->after('legal_imprint');
            $table->timestamp('terms_accepted_at')->nullable()->after('legal_copyright');
            $table->string('terms_accepted_by')->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'legal_privacy',
                'legal_imprint',
                'legal_copyright',
                'terms_accepted_at',
                'terms_accepted_by',
            ]);
        });
    }
};
