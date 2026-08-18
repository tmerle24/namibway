<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // Email is required on namibway.com where we know who we're talking
            // to, but optional for orders and table reservations placed through
            // a customer website — a street-food order is more like a tap-to-pay
            // than a travel enquiry. Phone-mode sites (WhatsApp contact) collect
            // a phone number instead; email stays null.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
