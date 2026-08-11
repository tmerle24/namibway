<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in this room.
 *
 * Hung off the reservation *unit* rather than the reservation, because that is
 * what per-occupancy pricing needs: two rooms on one booking can hold two
 * adults and two adults with a child, and those are different prices. It is
 * also why a room line's quantity is one when occupancy is priced — each room
 * has its own occupants, and a line for three rooms could not say whose.
 *
 * `reservations.adults` / `children` stay as they are: the summary an arrivals
 * board reads. This is the detail the price was computed from.
 *
 * The category is restricted on delete rather than nulled. A stay whose guests
 * became "unknown" would be unreadable at the desk, so a category in use is
 * deactivated instead of removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_category_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('count');
            $table->timestamps();

            $table->unique(['reservation_unit_id', 'guest_category_id'], 'reservation_guests_unit_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
