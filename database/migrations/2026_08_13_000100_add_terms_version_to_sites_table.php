<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version of our terms the business accepted.
 *
 * `terms_accepted_at` records the moment and `terms_accepted_by` the person;
 * without this the record is unanswerable the first time the terms change,
 * because "they accepted the terms" only means something if you can say which
 * text that was. Null on every existing row, honestly: those acceptances
 * predate there being a version to name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_by');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('terms_version');
        });
    }
};
