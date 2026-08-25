<?php

namespace Tests\Feature\Content;

use App\Enums\SupplyService;
use App\Models\SupplyPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filling in when a supply point is open, from the OpenStreetMap extract.
 *
 * The rules here are all ways of saying no — only blanks, only hours we can
 * read in full, only elements that answer for what the row sells — because the
 * one field this writes is the one a traveller acts on by driving to it. So
 * each refusal gets its own case.
 */
class SupplyHoursImportTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -24.0;

    private string $extract = '';

    protected function tearDown(): void
    {
        if ($this->extract !== '' && is_file($this->extract)) {
            unlink($this->extract);
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     */
    private function extract(array $elements): string
    {
        $this->extract = sys_get_temp_dir().'/supply-hours-'.uniqid().'.json';

        file_put_contents($this->extract, json_encode([
            'scraped_at' => '2026-08-25T00:00:00Z',
            'attribution' => '© OpenStreetMap contributors, ODbL',
            'elements' => $elements,
        ], JSON_THROW_ON_ERROR));

        return $this->extract;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function element(string $osm, float $lng, string $hours, string $kind = 'fuel', array $attributes = []): array
    {
        return [
            'osm' => $osm,
            'kind' => $kind,
            'name' => $osm,
            'lat' => self::LAT,
            'lng' => $lng,
            'opening_hours' => $hours,
        ] + $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function point(array $attributes = []): SupplyPoint
    {
        return SupplyPoint::factory()->create([
            'name' => 'Testkop',
            'slug' => 'testkop',
            'services' => [SupplyService::Fuel],
            'lat' => self::LAT,
            'lng' => 20.0,
        ] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function import(string $file, array $options = []): void
    {
        $this->artisan('namibway:import-supply-hours', ['--file' => $file] + $options)
            ->assertSuccessful();
    }

    public function test_it_fills_a_blank_and_says_which_element_it_read(): void
    {
        $point = $this->point();

        $this->import($this->extract([$this->element('node/1', 20.01, 'Mo-Sa 07:00-19:00')]));

        $point->refresh();

        $this->assertSame('Mo-Sa 07:00-19:00', $point->opening_hours);
        $this->assertSame('osm:node/1', $point->opening_hours_source);
        // Reading somebody else's map is not somebody checking the pumps.
        $this->assertNull($point->verified_at);
    }

    /**
     * A town has several forecourts and the traveller drives to whichever is
     * still open, so the row should say the best they can do there — not the
     * hours of whichever one happens to be nearest the town centre.
     */
    public function test_the_most_generous_element_wins_not_the_nearest(): void
    {
        $point = $this->point();

        $this->import($this->extract([
            $this->element('node/near', 20.001, 'Mo-Fr 08:00-17:00'),
            $this->element('node/far', 20.05, '24/7'),
        ]));

        $this->assertSame('24/7', $point->refresh()->opening_hours);
        $this->assertSame('osm:node/far', $point->opening_hours_source);
    }

    public function test_the_nearest_wins_a_tie(): void
    {
        $point = $this->point();

        $this->import($this->extract([
            $this->element('node/far', 20.05, 'Mo-Su 06:00-22:00'),
            $this->element('node/near', 20.001, 'Mo-Su 06:00-22:00'),
        ]));

        $this->assertSame('osm:node/near', $point->refresh()->opening_hours_source);
    }

    public function test_it_never_overwrites_hours_somebody_entered(): void
    {
        $point = $this->point(['opening_hours' => 'Mo-Fr 09:00-16:00']);

        $file = $this->extract([$this->element('node/1', 20.01, '24/7')]);

        $this->import($file);

        $this->assertSame('Mo-Fr 09:00-16:00', $point->refresh()->opening_hours);
        $this->assertNull($point->opening_hours_source);

        // …unless it is asked to, which is the point of the flag.
        $this->import($file, ['--overwrite' => true]);

        $this->assertSame('24/7', $point->refresh()->opening_hours);
    }

    /**
     * Real OSM carries plenty this parser will not read in full — month
     * ranges, sunset, "by appointment". Half-understood opening hours are
     * worse than none, so they are counted and dropped rather than stored.
     */
    public function test_hours_it_cannot_read_in_full_are_refused(): void
    {
        $point = $this->point();

        $this->import($this->extract([
            $this->element('node/1', 20.01, 'Mo-Fr sunrise-sunset'),
            $this->element('node/2', 20.02, 'Apr-Oct 08:00-17:00'),
        ]));

        $point->refresh();

        $this->assertNull($point->opening_hours);
        $this->assertNull($point->opening_hours_source);
    }

    public function test_a_supermarket_does_not_answer_for_the_pumps(): void
    {
        $point = $this->point();

        $this->import($this->extract([
            $this->element('node/1', 20.01, '24/7', 'supermarket'),
            $this->element('node/2', 20.02, '24/7', 'convenience'),
        ]));

        $this->assertNull($point->refresh()->opening_hours);
    }

    public function test_a_grocery_row_takes_a_supermarket_or_a_general_dealer(): void
    {
        $point = $this->point(['services' => [SupplyService::Groceries]]);

        $this->import($this->extract([
            $this->element('node/kiosk', 20.005, '24/7', 'convenience'),
            $this->element('node/shop', 20.01, 'Mo-Sa 08:00-18:00', 'general'),
        ]));

        $point->refresh();

        $this->assertSame('Mo-Sa 08:00-18:00', $point->opening_hours);
        $this->assertSame('osm:node/shop', $point->opening_hours_source);
    }

    public function test_an_element_in_the_next_town_is_not_this_town(): void
    {
        $point = $this->point();

        // ~50 km east at this latitude, well outside the default radius.
        $this->import($this->extract([$this->element('node/1', 20.5, '24/7')]));

        $this->assertNull($point->refresh()->opening_hours);
    }

    public function test_a_row_with_no_coordinates_is_left_alone(): void
    {
        $point = $this->point(['lat' => null, 'lng' => null]);

        $this->import($this->extract([$this->element('node/1', 20.01, '24/7')]));

        $this->assertNull($point->refresh()->opening_hours);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $point = $this->point();

        $this->import($this->extract([$this->element('node/1', 20.01, '24/7')]), ['--dry-run' => true]);

        $this->assertNull($point->refresh()->opening_hours);
    }

    public function test_it_says_so_rather_than_writing_when_there_is_no_extract(): void
    {
        $this->artisan('namibway:import-supply-hours', ['--file' => '/tmp/does-not-exist.json'])
            ->assertFailed();
    }
}
