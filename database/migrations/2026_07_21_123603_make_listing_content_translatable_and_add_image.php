<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->json('name_translations')->nullable()->after('name');
            $table->json('description_translations')->nullable()->after('description');
        });

        DB::statement("UPDATE listings SET name_translations = jsonb_build_object('en', name)");
        DB::statement("UPDATE listings SET description_translations = jsonb_build_object('en', description) WHERE description IS NOT NULL");

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['name', 'description']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->renameColumn('name_translations', 'name');
            $table->renameColumn('description_translations', 'description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('name_plain')->nullable()->after('name');
            $table->text('description_plain')->nullable()->after('description');
        });

        DB::statement("UPDATE listings SET name_plain = name->>'en'");
        DB::statement("UPDATE listings SET description_plain = description->>'en'");

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['name', 'description', 'image']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->renameColumn('name_plain', 'name');
            $table->renameColumn('description_plain', 'description');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
