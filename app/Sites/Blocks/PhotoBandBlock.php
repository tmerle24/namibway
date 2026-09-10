<?php

namespace App\Sites\Blocks;

/**
 * One photograph across the whole width of the screen, with a line over it.
 *
 * The pause between sections. A page of bands that all sit in the same
 * 1120px column reads as a form, however good each band is; one picture that
 * breaks out of the column is what makes it read as a magazine. Added
 * 2026-09-10 with the tour operator blocks.
 *
 * Not a section (see isSection()): no number, no anchor, no menu item.
 */
class PhotoBandBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'photo_band';
    }

    public function label(): string
    {
        return 'Photo band';
    }

    public function isSection(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image_id' => ['nullable', 'integer'],
            // Display type, over a photograph: one sentence, and a short one.
            'statement' => ['nullable', 'string', 'max:140'],
            'caption' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'image_id');
    }

    public function needsImage(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['image_id' => null, 'statement' => null, 'caption' => null];
    }
}
