<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which departure a stay was sold on.
 *
 * Needed for two different reasons, and both matter. Cancelling has to give
 * the seats back to the *departure* that holds them — releasing the day row
 * instead would leave the 09:00 tour full forever and quietly credit seats to
 * a pool nobody bought from. And an invoice for a quad tour that says only the
 * date has lost the part the guest actually turned up for.
 *
 * `slot_label` is frozen beside the link for the same reason `rate_plan_name`
 * is: an operator who renames "Morning departure" to "Sunrise ride" in March
 * must not change what a stay sold in February says it was.
 *
 * Null for everything sold by the period, which is every stay that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->after('room_type_id')
                ->constrained('booking_slots')->nullOnDelete();
            $table->string('slot_label')->nullable()->after('slot_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slot_id');
            $table->dropColumn('slot_label');
        });
    }
};
