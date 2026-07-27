<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->date('check_in')->nullable()->after('travel_dates');
            $table->date('check_out')->nullable()->after('check_in');
            $table->unsignedSmallInteger('adults')->default(2)->after('check_out');
            $table->unsignedSmallInteger('children')->default(0)->after('adults');
            $table->string('room_type_code')->nullable()->after('connector_reference');
            $table->decimal('total_amount', 10, 2)->nullable()->after('room_type_code');
            $table->char('currency', 3)->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['check_in', 'check_out', 'adults', 'children', 'room_type_code', 'total_amount', 'currency']);
        });
    }
};
