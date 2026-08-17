<?php

namespace App\Sites\Blocks;

class MissionBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'mission';
    }

    public function label(): string
    {
        return 'Our Mission';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:8000'],
            'image_id' => ['nullable', 'integer'],
            'image_side' => ['nullable', 'string', 'in:left,right'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'body');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => 'Our Mission', 'body' => null, 'image_id' => null, 'image_side' => 'right'];
    }

    /**
     * @return array<int, string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }
}
