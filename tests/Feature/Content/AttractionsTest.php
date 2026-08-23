<?php

namespace Tests\Feature\Content;

use App\Enums\AttractionType;
use App\Enums\ContentSource;
use App\Models\Attraction;
use App\Models\City;
use App\Models\Place;
use App\Models\User;
use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A thing you go and look at, where nothing is sold. It is neither a Listing
 * (no price, no partner) nor a Place (nothing is filed under it) — it sits in
 * one, and its own coordinates are what locate it.
 */
class AttractionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_attraction_sits_in_a_place_and_near_a_town(): void
    {
        $sossusvlei = Place::where('slug', 'sossusvlei')->firstOrFail();
        $city = City::where('slug', 'windhoek')->firstOrFail();

        $pan = Attraction::create([
            'name' => ['en' => 'A Pan With No Name'],
            'type' => AttractionType::NaturalFeature,
            'place_id' => $sossusvlei->id,
            'city_id' => $city->id,
            'lat' => -24.7592,
            'lng' => 15.2924,
            'visit_minutes' => 180,
        ]);

        $this->assertSame('a-pan-with-no-name', $pan->slug);
        $this->assertSame('Sossusvlei', $pan->place?->name);
        $this->assertSame('Windhoek', $pan->city?->name);
        $this->assertTrue($pan->isRoutable());

        // Nothing auto-publishes here, same as the namibweb importer. Read
        // back from the database: the default lives on the column, so the
        // in-memory model carries null until it is refreshed.
        $this->assertFalse($pan->fresh()->is_published);
    }

    public function test_without_coordinates_it_cannot_answer_what_is_near_here(): void
    {
        $hoba = Attraction::factory()->create(['lat' => null, 'lng' => null]);

        $this->assertFalse($hoba->isRoutable());
    }

    public function test_an_unchecked_access_fact_is_null_rather_than_no(): void
    {
        $site = Attraction::factory()->create();

        // The distinction the whole table rests on: false is a claim somebody
        // checked, null is a blank nobody has filled.
        $this->assertNull($site->requires_4x4);
        $this->assertNull($site->requires_permit);

        $site->update(['requires_4x4' => false]);

        $this->assertFalse($site->fresh()->requires_4x4);
    }

    public function test_a_directory_is_not_ours_to_publish(): void
    {
        $borrowed = Attraction::factory()->create(['description_source' => ContentSource::Directory]);
        $ours = Attraction::factory()->create(['description_source' => ContentSource::Manual]);

        $this->assertFalse($borrowed->isPublishable());
        $this->assertTrue($ours->isPublishable());
    }

    public function test_the_admin_screens_render(): void
    {
        // Sorted by slug, so this one is on the first page of the seeded 47 —
        // a factory row with a random name would land anywhere.
        $aiAis = Attraction::where('slug', 'ai-ais-hot-springs')->firstOrFail();

        $this->actingAs($this->admin())
            ->get('/admin/attractions')
            ->assertOk()
            ->assertSee('Ai-Ais Hot Springs');

        $this->actingAs($this->admin())
            ->get("/admin/attractions/{$aiAis->id}/edit")
            ->assertOk();
    }

    public function test_the_seeded_corpus_is_live_and_flagged_for_what_it_is(): void
    {
        $hoba = Attraction::where('slug', 'hoba-meteorite')->firstOrFail();

        $this->assertSame(AttractionType::Geology, $hoba->type);
        $this->assertTrue($hoba->is_published);
        $this->assertTrue($hoba->isRoutable());

        // ai_generated, not manual: the coordinates are written from knowledge
        // rather than read off a map, and the source column is what says so.
        $this->assertSame(ContentSource::AiGenerated, $hoba->description_source);

        // An invented fee is worse than none, so they are all left blank.
        $this->assertNull($hoba->entry_fee);
    }

    public function test_kaia_is_offered_the_published_ones_only(): void
    {
        $candidates = new \ReflectionMethod(ItineraryService::class, 'candidateAttractions');

        $before = $candidates->invoke(app(ItineraryService::class));
        $this->assertGreaterThan(30, $before->count(), 'The seeded corpus is what Kaia has to work with.');

        Attraction::query()->update(['is_published' => false]);

        $after = $candidates->invoke(app(ItineraryService::class));
        $this->assertCount(0, $after, 'Unpublishing takes one straight out of the trip plan.');
    }

    public function test_one_without_coordinates_is_kept_out_of_the_trip_plan(): void
    {
        $candidates = new \ReflectionMethod(ItineraryService::class, 'candidateAttractions');
        $service = app(ItineraryService::class);

        Attraction::query()->where('slug', '!=', 'hoba-meteorite')->update(['is_published' => false]);
        $this->assertCount(1, $candidates->invoke($service));

        // It cannot be drawn on the map or checked against where the traveller
        // is, so offering it would be offering a name and nothing else.
        Attraction::where('slug', 'hoba-meteorite')->update(['lat' => null]);

        $this->assertCount(0, $candidates->invoke($service));
    }
}
