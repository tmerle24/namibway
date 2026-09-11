<?php

namespace App\Sites\Blocks;

/**
 * A trip, stop by stop: day, place, the drive there, where you sleep.
 *
 * The one thing a tour operator's page is read for, and the one the offer
 * cards cannot carry. Generic on purpose — a multi-day activity, a hunting
 * package or a lodge's "your three days with us" has the same shape. Plain
 * text throughout; the day label is a string ("Day 1", "Days 3–4") because
 * that is how itineraries are written.
 */
class ItineraryBlock extends BlockDefinition
{
    public const MAX_ITEMS = 21;

    public function type(): string
    {
        return 'itinerary';
    }

    public function label(): string
    {
        return 'Itinerary';
    }

    public function navDefault(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.day' => ['nullable', 'string', 'max:24'],
            'items.*.title' => ['required', 'string', 'max:100'],
            // "300 km · 3½–4 hours"
            'items.*.drive' => ['nullable', 'string', 'max:60'],
            'items.*.text' => ['nullable', 'string', 'max:600'],
            // "Classic: Intu Afrika Zebra Lodge · Luxury: Bagatelle"
            'items.*.stay' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:300'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'items');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'intro' => null, 'items' => [], 'note' => null];
    }
}
