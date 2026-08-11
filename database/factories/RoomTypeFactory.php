<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            // `code` is unique per listing and is the key Inquiry matches on,
            // so it has to be genuinely unique rather than merely likely.
            'code' => 'RT'.fake()->unique()->numberBetween(1, 1000000),
            'name' => fake()->randomElement(['Standard Double', 'Family Chalet', 'Luxury Tent', 'Riverside Suite']),
            'max_adults' => 2,
            'max_children' => 2,
            'total_units' => 3,
            'rate_per_night' => fake()->randomFloat(2, 800, 4500),
            'currency' => 'NAD',
            'is_active' => true,
        ];
    }
}
