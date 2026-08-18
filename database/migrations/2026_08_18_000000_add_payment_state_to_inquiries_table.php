<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // Prototype values: awaiting_cash | simulated_paid
            // Full spec values (§5 of WEBSITES_TRADER_ORDERS.md): not_required |
            // link_issued | attested_by_trader | attested_by_traveller | disputed
            $table->string('payment_state')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('payment_state');
        });
    }
};
