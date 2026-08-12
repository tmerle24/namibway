<?php

namespace Tests\Feature\Sites;

use App\Enums\BusinessType;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Sites\Generation\SiteGenerator;
use App\Sites\HeroLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The line set in the largest type on the page.
 *
 * It used to be the business's name, which put the name twice on the first
 * screen — once in the bar and once four lines below it — and on a draft shown
 * to a prospect that reads as a bug. What has to hold now: the hero never
 * repeats the name, the line is stable across rebuilds so an approved draft
 * stays approved, and two businesses of the same kind do not open on the same
 * words.
 */
class SiteHeroLineTest extends TestCase
{
    use RefreshDatabase;

    private function heroOf(Site $site): SiteBlock
    {
        return SiteBlock::whereIn('site_page_id', $site->pages()->select('id'))
            ->where('type', 'hero')
            ->sole();
    }

    public function test_the_hero_does_not_repeat_the_business_name(): void
    {
        $listing = Listing::factory()->create(['name' => 'Dune Edge Lodge']);

        $site = app(SiteGenerator::class)->fromListing($listing);

        $this->assertNotSame('Dune Edge Lodge', $this->heroOf($site)->data['headline'] ?? null);
        $this->assertNotEmpty($this->heroOf($site)->data['headline'] ?? null);
    }

    /**
     * A draft that says something different every time it is rebuilt is not a
     * draft anybody can approve — and the generator tells "we wrote this" from
     * "somebody edited it" by comparing against what it wrote last time.
     */
    public function test_the_line_is_the_same_on_a_rebuild(): void
    {
        $listing = Listing::factory()->create(['name' => 'Dune Edge Lodge']);

        $first = $this->heroOf(app(SiteGenerator::class)->fromListing($listing))->data['headline'];
        $second = $this->heroOf(app(SiteGenerator::class)->fromListing($listing))->data['headline'];

        $this->assertSame($first, $second);
    }

    public function test_two_lodges_do_not_open_on_the_same_words(): void
    {
        $lines = collect(['alpha-lodge', 'beta-lodge', 'gamma-lodge', 'delta-lodge', 'epsilon-lodge'])
            ->map(fn (string $slug) => HeroLines::for(BusinessType::Accommodation, $slug))
            ->unique();

        $this->assertGreaterThan(1, $lines->count());
    }

    public function test_every_business_type_has_a_line(): void
    {
        foreach (BusinessType::cases() as $type) {
            $this->assertNotEmpty(HeroLines::for($type, 'some-slug'), $type->value.' has none');
        }
    }

    /**
     * The line is ours and the business's to replace, so a rebuild must keep an
     * edited one — the same rule every other generated field follows.
     */
    public function test_a_rebuild_keeps_a_headline_somebody_changed(): void
    {
        $listing = Listing::factory()->create(['name' => 'Dune Edge Lodge']);
        $site = app(SiteGenerator::class)->fromListing($listing);

        $hero = $this->heroOf($site);
        $data = $hero->data;
        $data['headline'] = 'Where the dunes begin';
        $hero->data = $data;
        $hero->save();

        app(SiteGenerator::class)->fromListing($listing->refresh());

        $this->assertSame('Where the dunes begin', $this->heroOf($site->refresh())->data['headline']);
    }
}
