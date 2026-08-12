<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three action buttons — book, call, WhatsApp — one switch each.
 *
 * Default true, because they are the point: a website whose visitor cannot act
 * on it is a brochure, and every one of these is switched on when a site is
 * generated. But each is a business decision that is not ours to make. A lodge
 * that takes no telephone bookings does not want a Call button; a business
 * whose WhatsApp is the owner's private mobile does not want it on every
 * screen; a property with no availability with us may not want a booking
 * button pointing at an enquiry form it does not answer.
 *
 * Switching a button off does not remove the detail behind it: the number
 * still appears in the contact section and the footer. This governs the
 * buttons, not the business's contact details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('show_book_button')->default(true)->after('whatsapp');
            $table->boolean('show_call_button')->default(true)->after('show_book_button');
            $table->boolean('show_whatsapp_button')->default(true)->after('show_call_button');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['show_book_button', 'show_call_button', 'show_whatsapp_button']);
        });
    }
};
