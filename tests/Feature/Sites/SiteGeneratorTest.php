<?php

namespace Tests\Feature\Sites;

use App\Enums\BusinessType;
use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\Listing;
use App\Models\Site;
use App\Sites\BlockRegistry;
use App\Sites\Generation\SiteGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Generation, and the property that makes it usable more than once.
 *
 * The draft is a first pass somebody then improves — that is the whole product.
 * So re-running has to refresh what it wrote and leave alone what has been
 * changed since, and say which is which. A generator that overwrites edits is a
 * generator nobody runs twice.
 */
class SiteGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    public function test_a_site_is_generated_from_a_listing(): void
    {
        $listing = Listing::factory()->create([
            'name' => 'Okonjima Bush Camp',
            'type' => ListingType::Accommodation,
            'description' => 'Leopards, and a very long driveway.',
            'description_source' => ContentSource::Partner,
            'phone' => '+264 67 304 566',
            'address' => 'Otjiwarongo',
        ]);

        $site = (new SiteGenerator)->fromListing($listing);

        $this->assertSame('Okonjima Bush Camp', $site->name);
        $this->assertSame(BusinessType::Accommodation, $site->business_type);
        $this->assertSame('+264 67 304 566', $site->contact_phone);
        $this->assertSame('Otjiwarongo', $site->address);
        $this->assertSame($listing->id, $site->source_listing_id);

        $types = $site->pages()->first()->blocks()->pluck('type')->all();
        $this->assertSame(BlockRegistry::layoutFor(BusinessType::Accommodation), $types);
    }

    public function test_a_guided_tour_operator_is_not_a_car_rental(): void
    {
        $listing = Listing::factory()->create([
            'type' => ListingType::Vehicle,
            'vehicle_category' => VehicleCategory::GuidedTour,
        ]);

        $this->assertSame(
            BusinessType::TourOperator,
            (new SiteGenerator)->fromListing($listing)->business_type,
        );
    }

    public function test_re_running_refreshes_what_it_wrote(): void
    {
        $listing = Listing::factory()->create(['phone' => '+264 61 111 111']);

        $generator = new SiteGenerator;
        $site = $generator->fromListing($listing);
        $this->assertSame('+264 61 111 111', $site->contact_phone);

        $listing->update(['phone' => '+264 61 222 222']);

        $site = (new SiteGenerator)->fromListing($listing->refresh());
        $this->assertSame('+264 61 222 222', $site->contact_phone);
    }

    public function test_re_running_never_overwrites_an_edit(): void
    {
        $listing = Listing::factory()->create(['phone' => '+264 61 111 111']);

        $site = (new SiteGenerator)->fromListing($listing);

        // Somebody rings the owner and gets the number they actually answer.
        // That correction is the point of the draft, and it has to survive.
        $site->update(['contact_phone' => '+264 81 999 999']);

        $listing->update(['phone' => '+264 61 222 222']);

        $generator = new SiteGenerator;
        $site = $generator->fromListing($listing->refresh());

        $this->assertSame('+264 81 999 999', $site->contact_phone);
        $this->assertContains('contact_phone', array_column($generator->report()->kept, 'field'));
    }

    public function test_re_running_never_overwrites_an_edited_block(): void
    {
        $listing = Listing::factory()->create([
            'description' => 'Scraped prose.',
            'description_source' => ContentSource::WebsiteScrape,
        ]);

        $site = (new SiteGenerator)->fromListing($listing);
        $about = $site->pages()->first()->blocks()->where('type', 'about')->first();

        $about->update(['data' => ['heading' => 'Our story', 'body' => 'Written properly by us.'] + $about->data]);

        $generator = new SiteGenerator;
        $generator->fromListing($listing->refresh());

        $this->assertSame('Written properly by us.', $about->refresh()->data['body']);
        $this->assertContains('about', array_column($generator->report()->kept, 'field'));
    }

    public function test_re_running_does_not_copy_the_photographs_again(): void
    {
        Storage::disk('r2')->put('listings/source/hero.jpg', 'bytes');

        $listing = Listing::factory()->create([
            'image' => 'listings/source/hero.jpg',
            'photos_source' => ContentSource::Partner,
        ]);

        $site = (new SiteGenerator)->fromListing($listing);
        $this->assertSame(1, $site->images()->count());

        (new SiteGenerator)->fromListing($listing);

        // Copying is the part with a bill attached.
        $this->assertSame(1, $site->refresh()->images()->count());
    }

    public function test_force_refuses_to_rebuild_a_published_site(): void
    {
        $listing = Listing::factory()->create();
        $site = (new SiteGenerator)->fromListing($listing);
        $site->forceFill(['status' => 'published', 'published_at' => now()])->save();

        $this->expectExceptionMessageMatches('/published/');

        (new SiteGenerator)->fromListing($listing, force: true);
    }

    public function test_a_business_with_no_listing_gets_the_same_kit(): void
    {
        $site = (new SiteGenerator)->empty('Swakop Auto Electric', BusinessType::Service);

        $this->assertNull($site->source_listing_id);
        $this->assertSame('swakop-auto-electric', $site->slug);

        $types = $site->pages()->first()->blocks()->pluck('type')->all();
        $this->assertSame(BlockRegistry::layoutFor(BusinessType::Service), $types);

        // Placeholders, present and empty. Nothing renders yet — an empty band
        // is what makes a site look unfinished — but every block is there to be
        // filled in.
        $this->assertGreaterThan(0, count($types));
    }

    public function test_the_command_generates_and_reports(): void
    {
        $listing = Listing::factory()->create([
            'name' => 'Namib Desert Lodge',
            'description_source' => ContentSource::Directory,
            'description' => 'Copied out of a directory.',
        ]);

        $this->artisan('sites:generate', ['listing' => $listing->slug])
            ->expectsOutputToContain('Namib Desert Lodge')
            ->assertSuccessful();

        $this->assertNotNull(Site::where('source_listing_id', $listing->id)->first());
    }

    public function test_the_command_needs_a_type_for_a_business_with_no_listing(): void
    {
        $this->artisan('sites:generate', ['--name' => 'Katutura Hardware'])
            ->assertFailed();

        $this->artisan('sites:generate', ['--name' => 'Katutura Hardware', '--type' => 'retail'])
            ->assertSuccessful();

        $this->assertNotNull(Site::where('name', 'Katutura Hardware')->first());
    }
}
