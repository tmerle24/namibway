<?php

namespace Database\Factories;

use App\Models\DocumentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentCategory>
 */
class DocumentCategoryFactory extends Factory
{
    protected $model = DocumentCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'icon' => 'heroicon-o-folder',
            'position' => 0,
        ];
    }
}
