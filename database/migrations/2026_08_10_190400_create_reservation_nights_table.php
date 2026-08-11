<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each night of a unit line actually cost — the standard nightly
 * breakdown. This is where a stay crossing a season boundary stops being a
 * special case: the rate is resolved per night from the calendar and written
 * down here, so the total is a sum rather than an assumption about which
 * season "the stay" belongs to.
 *
 * It is a price record, not an availability record. Availability is the
 * counter on room_type_calendar_days; deriving it from these rows instead
 * would reintroduce exactly the read-then-write race the counter exists to
 * close.
 *
 * `date` is the night, so a stay from the 5th to the 8th has rows for the
 * 5th, 6th and 7th — the departure day is not a night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_unit_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('units');
            $table->decimal('rate', 10, 2);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['reservation_unit_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_nights');
    }
};
