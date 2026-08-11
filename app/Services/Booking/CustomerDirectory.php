<?php

namespace App\Services\Booking;

use App\Models\Customer;
use App\Models\Partner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Who this booking is for — found if the property has met them, created if not.
 *
 * One class, because the matching rule has to be the same everywhere. A front
 * desk that recognises a returning guest and a website booking that does not
 * would produce two records for one person, and the record with the notes on
 * it would be the one nobody opens.
 *
 * ## The rule
 *
 * 1. **The NamibWay account** (`user_id`), when the booking has one. This is
 *    what survives somebody changing their email address, which is exactly the
 *    case an email-only match gets wrong — and it is how a traveller who books
 *    on namibway.com arrives already identified.
 * 2. **The email address**, lowercased and trimmed. This covers everyone
 *    without an account, which is most people at a desk.
 * 3. Otherwise a new customer. A walk-in with no email is still a customer:
 *    every booking gets one, so the customer view has no holes in it.
 *
 * The phone number is deliberately not a match key — see the migration.
 *
 * ## An existing customer is used, not overwritten
 *
 * A booking is one moment; a customer record is everything the property knows.
 * So a match fills in what is *missing* — the account they have just signed up
 * with, a phone number they had not given before — and never replaces a value
 * that is already there. Somebody mistyping a surname into a booking form must
 * not rewrite a record built over three seasons. Correcting a customer is its
 * own act, on the customer screen, where it is visible.
 */
class CustomerDirectory
{
    /**
     * @param  int|null  $userId  The NamibWay account this booking belongs to, where
     *                            there is one. Server-resolved — never taken from
     *                            request input, the same rule Inquiry.user_id follows.
     */
    public function resolve(
        Partner $partner,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?int $userId = null,
    ): Customer {
        $email = $this->normaliseEmail($email);
        $name = trim($name);

        $existing = $this->find($partner, $userId, $email);

        if ($existing !== null) {
            $this->fillGaps($existing, $name, $email, $phone, $userId);

            return $existing;
        }

        try {
            // The normalised phone and the lowercased email are written by the
            // model, so they are right however a customer is created.
            return Customer::create([
                'partner_id' => $partner->id,
                'user_id' => $userId,
                'name' => $name === '' ? 'Guest' : $name,
                'email' => $email,
                'phone' => $phone,
            ]);
        } catch (QueryException $clash) {
            // Two bookings for the same person landing at once. The unique
            // keys are the referee — the loser re-reads rather than guessing,
            // which is the same shape as the calendar's conditional UPDATE.
            $found = $this->find($partner, $userId, $email);

            if ($found === null) {
                throw $clash;
            }

            return $found;
        }
    }

    /** Whatever the property already knows about this person, or nothing. */
    private function find(Partner $partner, ?int $userId, ?string $email): ?Customer
    {
        if ($userId !== null) {
            $byAccount = Customer::query()
                ->where('partner_id', $partner->id)
                ->where('user_id', $userId)
                ->first();

            if ($byAccount !== null) {
                return $byAccount;
            }
        }

        if ($email === null) {
            return null;
        }

        return Customer::query()
            ->where('partner_id', $partner->id)
            ->where('email', $email)
            ->first();
    }

    /**
     * Add what is new and touch nothing that is already answered.
     *
     * The one write that matters here is `user_id`: the first time a customer
     * the desk created by hand signs in and books, the two records become one
     * person rather than staying two. It is only ever written when it was null.
     */
    private function fillGaps(
        Customer $customer,
        string $name,
        ?string $email,
        ?string $phone,
        ?int $userId,
    ): void {
        $changes = [];

        if ($userId !== null && $customer->user_id === null) {
            $changes['user_id'] = $userId;
        }

        if ($email !== null && blank($customer->email)) {
            $changes['email'] = $email;
        }

        if ($phone !== null && trim($phone) !== '' && blank($customer->phone)) {
            $changes['phone'] = trim($phone);
        }

        if ($name !== '' && blank($customer->name)) {
            $changes['name'] = $name;
        }

        if ($changes !== []) {
            $customer->fill($changes)->save();
        }
    }

    /**
     * Lowercased and trimmed, because that is what the unique key stores — an
     * address is not two people because somebody capitalised it.
     */
    private function normaliseEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
