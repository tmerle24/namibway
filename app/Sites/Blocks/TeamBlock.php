<?php

namespace App\Sites\Blocks;

/**
 * The people. For a guide, a chef or a mechanic, who does the work is the
 * product — a traveller booking a day in a vehicle with a stranger wants to see
 * the stranger first.
 *
 * One person renders as a portrait beside their story ("Meet your guide");
 * several render as a row of portraits. Added 2026-09-10.
 */
class TeamBlock extends BlockDefinition
{
    public const MAX_ITEMS = 8;

    public function type(): string
    {
        return 'team';
    }

    public function label(): string
    {
        return 'Team';
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
            'items.*.name' => ['required', 'string', 'max:80'],
            'items.*.role' => ['nullable', 'string', 'max:80'],
            'items.*.text' => ['nullable', 'string', 'max:900'],
            'background_image_id' => ['nullable', 'integer'],
            // A few short facts beside the story ("Base: Otjiwarongo").
            'items.*.facts' => ['nullable', 'array', 'max:6'],
            'items.*.facts.*.label' => ['required', 'string', 'max:24'],
            'items.*.facts.*.value' => ['required', 'string', 'max:60'],
            'items.*.image_id' => ['nullable', 'integer'],
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
        return ['heading' => null, 'intro' => null, 'items' => []];
    }
}
