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
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:8000'],
        ];
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
        return ['heading' => 'Our Mission', 'body' => null];
    }

    /**
     * @return array<int, string>
     */
    public function richTextFields(): array
    {
        return ['body'];
    }
}
