<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->jsonb('social_links')->nullable()->after('facebook');
        });

        // Carry forward any existing instagram/facebook values
        DB::statement(<<<'SQL'
            UPDATE partners
            SET social_links = (
                SELECT jsonb_strip_nulls(jsonb_build_object(
                    'instagram', NULLIF(instagram, ''),
                    'facebook',  NULLIF(facebook,  '')
                ))
            )
            WHERE instagram IS NOT NULL
               OR facebook  IS NOT NULL
        SQL);

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['instagram', 'facebook']);
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('instagram')->nullable();
            $table->string('facebook')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE partners
            SET instagram = social_links->>'instagram',
                facebook  = social_links->>'facebook'
            WHERE social_links IS NOT NULL
        SQL);

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('social_links');
        });
    }
};
