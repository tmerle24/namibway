<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line items of a reservation: "two Standard and one Family, 5th to 8th".
 * This is what the existing Inquiry cannot express — it has no quantity
 * column, so today three rooms for one party are three separate requests.
 *
 * room_type_id is restricted rather than cascaded: a room type with stays
 * against it must not vanish and take the record of them with it. That is
 * the same choice cities.region_id already makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('quantity');
            $table->date('check_in');
            $table->date('check_out');

            $table->decimal('total_amount', 12, 2);
            $table->char('currency', 3);

            $table->timestamps();

            $table->index(['room_type_id', 'check_in', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_units');
    }
};
