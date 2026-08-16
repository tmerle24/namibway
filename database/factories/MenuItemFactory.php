<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'name' => fake()->randomElement([
                'Oryx fillet', 'Kudu carpaccio', 'Potjiekos', 'Windhoek Lager', 'Malva pudding',
            ]).' '.fake()->unique()->numberBetween(1, 1000000),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['Starters', 'Mains', 'Desserts', 'Drinks']),
            'price' => fake()->randomFloat(2, 25, 350),
            'currency' => 'NAD',
            'is_available' => true,
            'sort' => 0,
        ];
    }

    public function unavailable(): self
    {
        return $this->state(fn (): array => ['is_available' => false]);
    }
}
