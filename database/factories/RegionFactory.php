<?php

namespace Database\Factories;

use App\Models\Region;
use Database\Factories\Concerns\UniqueAcrossTheRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    use UniqueAcrossTheRun;

    protected $model = Region::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Both the name and the slug are unique columns, and faker's own
        // unique() is not enough to hold them — see UniqueAcrossTheRun.
        $name = $this->neverSeenBefore(fake()->city());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'country_code' => 'NA',
        ];
    }
}
