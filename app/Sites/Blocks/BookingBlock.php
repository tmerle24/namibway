<?php

namespace App\Sites\Blocks;

/**
 * What is free and what it costs — read live, every time, or not shown at all.
 *
 * ## Why nothing here is stored
 *
 * The payload holds a heading and a sentence. Availability, rates, taxes and
 * units-left are read at render time from the same calendar the lodge sells
 * from (App\Services\Booking\RoomOffers), because a price copied into a website
 * is a price that can be wrong, and a wrong price on a page that says "book" is
 * the one kind of stale content that costs somebody money.
 *
 * That read is a direct service call in the renderer, not an HTTP request to
 * our own public API. The API at /api/v1 is token-authenticated for partner
 * integrations, holds no booking-creation endpoint, and answers availability
 * out of the partner's connector rather than our calendar — so it would be the
 * wrong answer fetched the expensive way.
 *
 * ## Why this block often is not there
 *
 * It renders only where the booking system actually holds this property's
 * inventory. A restaurant has nothing to sell with dates; a lodge on somebody
 * else's PMS has nothing we can quote. Where it cannot render, the contact
 * block takes its place — an enquiry that reaches a human is worth more than a
 * booking form that cannot answer.
 */
class BookingBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'booking';
    }

    public function label(): string
    {
        return 'Booking';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:300'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        // Nothing in the payload is required — what makes this block worth
        // rendering is whether the property has anything to sell, and that is
        // a question about the booking system, not about this row. The
        // renderer answers it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'heading' => 'Availability',
            'intro' => null,
        ];
    }
}
