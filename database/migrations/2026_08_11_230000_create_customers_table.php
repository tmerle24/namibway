<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people a property sells to.
 *
 * Every application system of this kind has a small number of main entities,
 * and the customer is one of them — this one went without for six slices, and
 * that was a real gap rather than a scoping decision: a reservation carried a
 * typed-in name and email and nothing tied last March's stay to this one.
 *
 * ## Why "customer" and not "guest"
 *
 * The same system is sold to quad tour operators and car rental companies,
 * where nobody is a guest. And `guest` is already taken in this schema with a
 * different meaning: `reservation_guests` is how many people of which category
 * are in a room, and `guest_categories` are age bands. Two meanings for one
 * word in one schema is how a query gets written against the wrong table.
 *
 * ## Scoped to the partner, not global
 *
 * A customer belongs to the property's business. Two lodges that both host the
 * same traveller each keep their own record with their own notes, because one
 * operator's note about a guest is not the other's to read — and because
 * merging them would make the booking system a shared address book, which is
 * not something a partner agreed to.
 *
 * ## How somebody is recognised
 *
 * `user_id` first, then `email` — in that order, and that order matters. A
 * traveller who books on namibway.com arrives with an account, and an account
 * survives them changing their email address; an email does not survive it.
 * The email is still an identity for everyone who has no account, which is
 * most people at a front desk.
 *
 * Both uniques rely on SQL treating NULLs as distinct, which is what makes a
 * property able to hold a hundred walk-ins with no email and no account
 * without them colliding into one record.
 *
 * Deliberately **not** a match key: the phone number. A number at a lodge is
 * often a couple's single mobile, or the tour operator who made the booking.
 * Merging two people into one customer is far harder to undo than having two
 * records, so the phone is stored normalised and searchable, and a human at
 * the desk decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();

            // The NamibWay account, once there is one. This is what closes the
            // circle from the traveller-facing site: somebody who signs in,
            // plans a trip and books arrives here already identified.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');

            // Stored lowercased and trimmed, so uniqueness is case-insensitive
            // without an expression index — CustomerDirectory owns that
            // normalisation and is the only thing that should write here.
            $table->string('email')->nullable();

            // Both kept: the international form is what is matched and
            // searched, the typed form is what the desk recognises.
            $table->string('phone')->nullable();
            $table->string('phone_normalised')->nullable();

            $table->string('country', 2)->nullable();
            $table->string('language', 5)->nullable();

            $table->timestamps();

            $table->unique(['partner_id', 'user_id']);
            $table->unique(['partner_id', 'email']);

            // The desk searches by phone far more often than by anything else,
            // because it is what a guest reads out over a bad line.
            $table->index(['partner_id', 'phone_normalised']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
