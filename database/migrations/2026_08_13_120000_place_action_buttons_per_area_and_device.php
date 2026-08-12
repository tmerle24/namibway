<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each action button appears, rather than only whether it exists.
 *
 * This morning's three booleans said "show the Call button" and the renderer
 * decided where. That turned out to be the wrong half of the decision: the same
 * button is right in one place and wrong in another, and which place depends on
 * the screen. A booking button in the menu is redundant next to a "Request
 * availability" band and pushes the business's name into a second line; on a
 * phone the same button is exactly right at the foot of the screen. WhatsApp is
 * worth a button on a phone and close to useless on a desktop.
 *
 * So the unit is a *placement*: an action, an area (menu, hero, foot of the
 * screen) and a device (phone, desktop). `action_buttons` holds them, with the
 * label — and, for the custom button, the link — beside them. Null means the
 * defaults in App\Sites\ActionButtons, which is what every site generated so
 * far gets without being touched.
 *
 * The three booleans go. They shipped this morning, nothing else reads them,
 * and keeping them beside this would leave two answers to the same question.
 *
 * `brand_name` is the other half of the same screenshot: the name in the bar is
 * the site's `name` today, with no way to shorten it or to say where it breaks.
 * A separate column rather than editing `name`, because the name is also the
 * page title, the og:title and what the panel lists — a line break belongs in
 * the bar and nowhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->json('action_buttons')->nullable()->after('whatsapp');
            $table->string('brand_name')->nullable()->after('name');
            $table->dropColumn(['show_book_button', 'show_call_button', 'show_whatsapp_button']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['action_buttons', 'brand_name']);
            $table->boolean('show_book_button')->default(true);
            $table->boolean('show_call_button')->default(true);
            $table->boolean('show_whatsapp_button')->default(true);
        });
    }
};
