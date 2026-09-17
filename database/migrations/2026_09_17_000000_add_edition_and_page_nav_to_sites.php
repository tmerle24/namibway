<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            // standard | enterprise - see App\Sites\SiteEdition
            $table->string('edition', 16)->default('standard')->after('business_type');
        });

        Schema::table('site_pages', function (Blueprint $table): void {
            // menu label, when the page title (the <title>) is too long for the bar
            $table->string('nav_label', 40)->nullable()->after('title');
            // a tour page is reached from its card, not from the menu
            $table->boolean('show_in_nav')->default(true)->after('nav_label');
        });
    }

    public function down(): void
    {
        Schema::table('site_pages', function (Blueprint $table): void {
            $table->dropColumn(['nav_label', 'show_in_nav']);
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn('edition');
        });
    }
};
