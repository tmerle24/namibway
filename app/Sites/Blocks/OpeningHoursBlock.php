<?php

namespace App\Sites\Blocks;

/**
 * When the doors are open.
 *
 * Free text per day rather than parsed times, and deliberately so: "08:00–17:00,
 * closed 13:00–14:00", "by appointment", "Sundays in season only" are all real
 * answers, and a time picker that cannot express them produces a website that
 * says something false about the business.
 *
 * The listing column this imports from (`opening_hours`) is written only by the
 * AI extractor reading an owner's own website, so in practice it is empty for
 * almost every listing and this block starts switched off. That is the honest
 * state — an opening-hours table nobody confirmed is worse than none.
 */
class OpeningHoursBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'opening_hours';
    }

    public function label(): string
    {
        return 'Opening hours';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'days' => ['array', 'max:8'],
            'days.*.day' => ['required', 'string', 'max:40'],
            'days.*.hours' => ['required', 'string', 'max:60'],
        ];
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'days');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => 'Opening hours', 'note' => null, 'days' => []];
    }
}
