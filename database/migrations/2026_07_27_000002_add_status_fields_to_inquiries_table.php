<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('message');
            $table->string('connector_reference')->nullable()->after('status')
                ->comment('Reference ID returned by the booking connector');
            $table->text('notes')->nullable()->after('connector_reference')
                ->comment('Internal notes for the NamibWay team (e.g. NWR manual check log)');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['status', 'connector_reference', 'notes']);
        });
    }
};
