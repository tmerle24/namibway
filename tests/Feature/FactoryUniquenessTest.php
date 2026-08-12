<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The factories that fill unique columns must not depend on faker remembering
 * anything, because the thing they have to be unique against outlives faker's
 * memory. The full argument is in Database\Factories\Concerns\UniqueAcrossTheRun.
 *
 * Reproduced here by doing to faker exactly what a test boundary does: reset
 * the unique memory and put the seed back, so the next draw is guaranteed to
 * be the same word again. Before the fix the second create was a
 * UniqueConstraintViolationException; this is that CI failure, made to happen
 * on purpose rather than once a fortnight.
 */
class FactoryUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function rewindFaker(): void
    {
        fake()->unique(reset: true);
        fake()->seed(1);
    }

    public function test_two_regions_from_the_same_draw_do_not_collide(): void
    {
        $this->rewindFaker();
        $first = Region::factory()->create();

        $this->rewindFaker();
        $second = Region::factory()->create();

        $this->assertNotSame($first->name, $second->name);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_two_cities_from_the_same_draw_do_not_collide(): void
    {
        $this->rewindFaker();
        $first = City::factory()->create();

        $this->rewindFaker();
        $second = City::factory()->create();

        $this->assertNotSame($first->slug, $second->slug);
    }
}
