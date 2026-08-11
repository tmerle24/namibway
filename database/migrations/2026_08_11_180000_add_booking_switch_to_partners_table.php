<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a lodge's bookings are real yet, and where their mail goes.
 *
 * Three states, and the difference between them is only ever *who receives
 * the mail* — the calendar, the prices and the booking form work identically
 * in all three, because a system a partner is still evaluating has to be the
 * system they will get.
 *
 * 1. **Not enabled** (the default, and where every partner starts). Bookings
 *    can be taken and everything works, but every confirmation goes to the
 *    NamibWay team mailbox as a plus-address naming the property. Nothing
 *    reaches a guest or a lodge owner by accident while an account is being
 *    set up.
 * 2. **Demo mode.** The operator is testing against their own real inventory
 *    and wants to see the mail themselves, so all of it goes to one address
 *    they choose. Still nothing escapes: a test booking cannot mail a guest.
 * 3. **Enabled.** Guests get their confirmations and the lodge gets its
 *    notifications, at `booking_email` where they have given one.
 *
 * `booking_enabled` is deliberately not something a partner can switch on
 * themselves — going live means real guests receive real mail in NamibWay's
 * name. Demo mode and the addresses are theirs to set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('booking_enabled')->default(false)->after('is_demo');
            $table->boolean('booking_demo_mode')->default(false)->after('booking_enabled');

            // Kept apart from `email`, which is the contact address a partner
            // was reached on: reservations@ and info@ are different desks at
            // most properties, and a booking that lands in the wrong inbox is
            // a booking nobody answers.
            $table->string('booking_email')->nullable()->after('booking_demo_mode');
            $table->string('booking_demo_email')->nullable()->after('booking_email');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'booking_enabled',
                'booking_demo_mode',
                'booking_email',
                'booking_demo_email',
            ]);
        });
    }
};
