<?php

namespace Tests\Feature;

use App\Enums\ContentSource;
use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportNamibwebCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    public function test_it_creates_a_listing_but_never_publishes_it(): void
    {
        $path = $this->writeFixture([$this->record()]);

        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();

        $this->assertSame('Hohewarte Guest House', $listing->name);
        $this->assertSame(ListingType::Accommodation, $listing->type);
        $this->assertSame('namibweb', $listing->scrape_source);
        $this->assertSame('info@hohewarte.com', $listing->contact_email);
        $this->assertSame(['facebook' => 'https://www.facebook.com/hohewarte'], $listing->social_links);
        // The text and the photos are namibweb's, not ours — nothing goes live
        // off the back of a scrape.
        $this->assertFalse($listing->is_published);
        $this->assertNull($listing->description);
    }

    public function test_it_keeps_the_upstream_text_as_source_material_even_when_it_is_not_published(): void
    {
        $path = $this->writeFixture([$this->record()]);

        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();

        $this->assertSame(
            'Eight en-suite rooms overlooking Windhoek.',
            $listing->scrape_data['namibweb']['upstream']['description']
        );
        $this->assertNotEmpty($listing->scrape_data['namibweb']['raw']['text_blocks']);
    }

    public function test_with_descriptions_writes_the_upstream_text_into_the_description(): void
    {
        $path = $this->writeFixture([$this->record()]);

        $this->artisan('listings:import-namibweb', ['--file' => $path, '--with-descriptions' => true])
            ->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();

        $this->assertSame('Eight en-suite rooms overlooking Windhoek.', $listing->description);
        // Marked as directory text, which is what later lets the website crawler
        // or our own generated copy replace it — see ContentSourceLadderTest.
        $this->assertSame(ContentSource::Directory, $listing->description_source);
    }

    public function test_photos_are_staged_as_reference_only_and_never_claim_the_live_slot(): void
    {
        $photosDir = sys_get_temp_dir().'/namibweb-photos-'.uniqid();
        mkdir($photosDir.'/hohewarte', 0777, true);
        file_put_contents($photosDir.'/hohewarte/00-hero.jpg', 'jpeg-bytes');

        $record = $this->record([
            'photos' => ['https://www.namibweb.com/photos/hero.jpg'],
            'photo_files' => [[
                'url' => 'https://www.namibweb.com/photos/hero.jpg',
                'file' => 'hohewarte/00-hero.jpg',
                'sha256' => str_repeat('a', 64),
                'bytes' => 10,
            ]],
        ]);

        $this->artisan('listings:import-namibweb', [
            '--file' => $this->writeFixture([$record]),
            '--photos-dir' => $photosDir,
        ])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();

        $this->assertNull($listing->image, 'a directory photo must never be the live image');
        $this->assertNotNull($listing->pending_image);
        $this->assertSame(ContentSource::Directory, $listing->pending_photos_source);
        $this->assertNull($listing->photos_source);
        $this->assertFalse($listing->hasApprovablePhotos());
    }

    public function test_a_second_import_of_the_same_data_changes_nothing(): void
    {
        $path = $this->writeFixture([$this->record()]);

        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();
        $before = Listing::where('scrape_id', 'hohewarte')->firstOrFail()->updated_at;

        $this->travel(1)->minutes();

        $this->artisan('listings:import-namibweb', ['--file' => $path])
            ->expectsOutputToContain('Unchanged')
            ->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $this->assertSame($before->timestamp, $listing->updated_at->timestamp);
    }

    public function test_it_applies_an_upstream_change_to_a_field_we_have_not_touched(): void
    {
        $path = $this->writeFixture([$this->record()]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $updated = $this->record([
            'phone' => '+264 61 999 111',
            'hashes' => ['contact' => 'contact-v2'],
        ]);
        $this->artisan('listings:import-namibweb', ['--file' => $this->writeFixture([$updated])])
            ->assertSuccessful();

        $this->assertSame('+264 61 999 111', Listing::where('scrape_id', 'hohewarte')->value('phone'));
    }

    /**
     * The case the whole design exists for: we rewrite what a scrape gave us,
     * then the source edits the same field. Our version has to survive.
     */
    public function test_it_refuses_to_overwrite_a_field_we_edited_ourselves(): void
    {
        $path = $this->writeFixture([$this->record()]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $listing->update(['phone' => '+264 61 000 000']);

        $updated = $this->record([
            'phone' => '+264 61 999 111',
            'hashes' => ['contact' => 'contact-v2'],
        ]);

        $this->artisan('listings:import-namibweb', ['--file' => $this->writeFixture([$updated])])
            ->expectsOutputToContain('edited on our side')
            ->assertSuccessful();

        $this->assertSame('+264 61 000 000', $listing->refresh()->phone);
    }

    public function test_a_conflicted_field_stays_protected_on_every_later_run(): void
    {
        $path = $this->writeFixture([$this->record()]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $listing->update(['phone' => '+264 61 000 000']);

        // Upstream keeps editing; the same field must keep losing.
        foreach (['contact-v2' => '+264 61 999 111', 'contact-v3' => '+264 61 888 222'] as $hash => $phone) {
            $this->artisan('listings:import-namibweb', [
                '--file' => $this->writeFixture([$this->record(['phone' => $phone, 'hashes' => ['contact' => $hash]])]),
            ])->assertSuccessful();
        }

        $this->assertSame('+264 61 000 000', $listing->refresh()->phone);
    }

    public function test_it_fills_a_field_we_left_empty_even_when_another_field_conflicts(): void
    {
        $path = $this->writeFixture([$this->record(['website' => null])]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $listing->update(['phone' => '+264 61 000 000']);

        $this->artisan('listings:import-namibweb', [
            '--file' => $this->writeFixture([$this->record([
                'phone' => '+264 61 999 111',
                'website' => 'https://hohewarte.com',
                'hashes' => ['contact' => 'contact-v2'],
            ])]),
        ])->assertSuccessful();

        $listing->refresh();
        $this->assertSame('+264 61 000 000', $listing->phone, 'our edit survives');
        $this->assertSame('https://hohewarte.com', $listing->website, 'the empty field still gets filled');
    }

    public function test_an_unchanged_hash_group_is_not_even_considered(): void
    {
        $path = $this->writeFixture([$this->record()]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $listing->update(['phone' => '+264 61 000 000']);

        // New phone upstream, but the contact hash says nothing changed — we
        // trust the hash and never look at the value, so no conflict is raised.
        $this->artisan('listings:import-namibweb', [
            '--file' => $this->writeFixture([$this->record(['phone' => '+264 61 999 111'])]),
        ])->assertSuccessful();

        $this->assertSame('+264 61 000 000', $listing->refresh()->phone);
    }

    public function test_it_resolves_the_city_from_coordinates_rather_than_the_address_text(): void
    {
        $region = Region::factory()->create();
        $windhoek = City::factory()->create(['name' => 'Windhoek', 'region_id' => $region->id, 'lat' => -22.5609, 'lng' => 17.0658]);
        $swakop = City::factory()->create(['name' => 'Swakopmund', 'region_id' => $region->id, 'lat' => -22.6784, 'lng' => 14.5258]);

        // The postal address says Windhoek — a very common pattern for remote
        // lodges — but the GPS is on the coast, and the GPS wins.
        $path = $this->writeFixture([$this->record([
            'latitude' => -22.6790,
            'longitude' => 14.5260,
            'address' => 'P.O. Box 1234, Windhoek, Namibia',
        ])]);

        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $this->assertSame($swakop->id, Listing::where('scrape_id', 'hohewarte')->value('city_id'));
        $this->assertNotSame($windhoek->id, Listing::where('scrape_id', 'hohewarte')->value('city_id'));
    }

    public function test_it_builds_an_address_out_of_the_reverse_geocoded_location_when_the_page_has_none(): void
    {
        $path = $this->writeFixture([$this->record([
            'address' => null,
            'geo' => [
                'road' => 'Hohewarte Street',
                'place' => 'Windhoek',
                'postcode' => '10005',
                'display_name' => 'Hohewarte Street, Windhoek, Namibia',
            ],
        ])]);

        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $this->assertSame(
            'Hohewarte Street, Windhoek, 10005',
            Listing::where('scrape_id', 'hohewarte')->value('address')
        );
    }

    public function test_a_listing_that_vanished_upstream_is_flagged_not_unpublished(): void
    {
        $path = $this->writeFixture([$this->record()]);
        $this->artisan('listings:import-namibweb', ['--file' => $path])->assertSuccessful();

        $listing = Listing::where('scrape_id', 'hohewarte')->firstOrFail();
        $listing->update(['is_published' => true]);

        $this->artisan('listings:import-namibweb', [
            '--file' => $this->writeFixture([$this->record(['gone' => true, 'gone_since' => '2026-09-01T00:00:00+00:00'])]),
        ])
            ->expectsOutputToContain('no longer on namibweb.com')
            ->assertSuccessful();

        $listing->refresh();
        $this->assertTrue($listing->is_published);
        $this->assertSame('2026-09-01T00:00:00+00:00', $listing->scrape_data['namibweb']['gone_since']);
    }

    public function test_it_enriches_a_listing_another_source_already_created_instead_of_duplicating_it(): void
    {
        $existing = Listing::factory()->create([
            'name' => 'Hohewarte Guest House',
            'scrape_source' => 'ntb',
            'phone' => null,
            'website' => null,
        ]);

        $this->artisan('listings:import-namibweb', ['--file' => $this->writeFixture([$this->record()])])
            ->assertSuccessful();

        $this->assertSame(1, Listing::where('name->en', 'Hohewarte Guest House')->count());
        $existing->refresh();
        $this->assertSame('+264 61 232 890', $existing->phone);
        $this->assertSame('ntb', $existing->scrape_source, 'the original source keeps ownership of the row');
        $this->assertSame('hohewarte', $existing->scrape_data['namibweb']['scrape_id']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('listings:import-namibweb', [
            '--file' => $this->writeFixture([$this->record()]),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, Listing::count());
    }

    public function test_changed_only_skips_records_the_scraper_called_unchanged(): void
    {
        $path = $this->writeFixture([
            $this->record(['scrape_id' => 'quiet', 'name' => 'Quiet Lodge', 'status' => 'unchanged']),
            $this->record(['scrape_id' => 'busy', 'name' => 'Busy Lodge', 'status' => 'changed']),
        ]);

        $this->artisan('listings:import-namibweb', ['--file' => $path, '--changed-only' => true])
            ->assertSuccessful();

        $this->assertSame(0, Listing::where('scrape_id', 'quiet')->count());
        $this->assertSame(1, Listing::where('scrape_id', 'busy')->count());
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): array
    {
        $hashes = array_merge([
            'name' => 'name-v1',
            'description' => 'description-v1',
            'contact' => 'contact-v1',
            'photos' => 'photos-v1',
            'social' => 'social-v1',
            'facts' => 'facts-v1',
            'content' => 'content-v1',
        ], $overrides['hashes'] ?? []);

        unset($overrides['hashes']);

        return array_merge([
            'scrape_id' => 'hohewarte',
            'source_url' => 'https://www.namibweb.com/hohewarte.htm',
            'index_url' => 'https://www.namibweb.com/accsummary.htm',
            'name' => 'Hohewarte Guest House',
            'region' => 'Windhoek',
            'listing_type' => 'accommodation',
            'category' => 'guesthouse',
            'description' => 'Eight en-suite rooms overlooking Windhoek.',
            'photos' => [],
            'photo_files' => [],
            'social_links' => ['facebook' => 'https://www.facebook.com/hohewarte'],
            'email' => 'info@hohewarte.com',
            'emails' => ['info@hohewarte.com'],
            'phone' => '+264 61 232 890',
            'phones' => ['+264 61 232 890'],
            'website' => 'https://www.hohewarte.com',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'price_from' => 1250.0,
            'price_currency' => 'NAD',
            'facilities' => ['pool'],
            'raw' => ['title' => 'Hohewarte', 'text_blocks' => ['Eight en-suite rooms overlooking Windhoek.']],
            'status' => 'new',
            'revision' => 1,
            'hashes' => $hashes,
        ], $overrides);
    }

    /** @param array<int, array<string, mixed>> $records */
    private function writeFixture(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'namibweb').'.json';
        file_put_contents($path, json_encode(['scraped_at' => '2026-08-10T00:00:00+00:00', 'records' => $records]));

        return $path;
    }
}
