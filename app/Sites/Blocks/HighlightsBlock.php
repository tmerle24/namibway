<?php

namespace App\Sites\Blocks;

/**
 * Three or four reasons to come, as short titled points.
 *
 * This is the block a listing fills best — `highlights` on a listing is already
 * exactly this shape — and the one a business without a listing writes first,
 * because it is the question they answer on the phone every day.
 */
class HighlightsBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'highlights';
    }

    public function label(): string
    {
        return 'Highlights';
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
            'items' => ['array', 'max:8'],
            'items.*.title' => ['required', 'string', 'max:80'],
            'items.*.text' => ['nullable', 'string', 'max:300'],
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
        return ['heading' => 'What we offer', 'items' => []];
    }
}
