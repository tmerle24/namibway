<?php

namespace Tests\Feature\Sites;

use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The tour request: pick one of the business's fixed-length offers, a start
 * date and a party. The end is the tour's to decide, and the request lands on
 * the tour — so the business sees which one was asked for.
 */
class SiteTourRequestTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    private Site $site;

    private Listing $tour;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->partner = Partner::create(['name' => 'Epima Tours & Safaris']);
        $operator = Listing::factory()->create(['partner_id' => $this->partner->id, 'name' => 'Epima Tours & Safaris', 'accepts_inquiries' => true, 'is_published' => false]);

        $this->tour = $this->tourListing('Namibia Top 3', 8 * 24 * 60);

        $this->site = Site::factory()->create(['partner_id' => $this->partner->id, 'source_listing_id' => $operator->id, 'slug' => 'tour-site']);
        $page = SitePage::factory()->create(['site_id' => $this->site->id, 'is_home' => true, 'slug' => '']);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'offers', 'sort' => 0, 'data' => ['items' => [
            ['title' => 'Namibia Top 3', 'listing_slug' => $this->tour->slug],
        ]]]);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'enquiry', 'sort' => 1, 'data' => [
            'form_type' => EnquiryFormType::TourRequest->value, 'channel' => EnquiryBlock::CHANNEL_EMAIL,
        ]]);
    }

    private function tourListing(string $name, ?int $minutes, array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + [
            'partner_id' => $this->partner->id,
            'name' => $name,
            'type' => ListingType::Vehicle,
            'vehicle_category' => VehicleCategory::GuidedTour,
            'duration_minutes' => $minutes,
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);
    }

    private function send(array $fields): TestResponse
    {
        return $this->post('/_sites/tour-site/enquiry', $fields + [
            'name' => 'Anna Weber',
            'email' => 'anna@example.com',
            'website' => '',
        ]);
    }

    public function test_the_form_lists_the_partners_fixed_length_listings_only(): void
    {
        $this->tourListing('Half-day drive', 240);
        $this->tourListing('Unpublished tour', 1440, ['is_published' => false]);
        $this->tourListing('No length', null);
        $otherPartner = Partner::create(['name' => 'Somebody else']);
        $this->tourListing('Their tour', 1440, ['partner_id' => $otherPartner->id]);

        $html = (string) $this->get('/_sites/tour-site?preview='.$this->site->draft_token)->assertOk()->getContent();

        $this->assertStringContainsString('<select id="eq-tour" name="listing_id">', $html);
        $this->assertStringContainsString('Namibia Top 3 — 8 days', $html);
        $this->assertStringContainsString('Half-day drive — 1 day', $html);
        $this->assertStringContainsString('Private / tailor-made tour', $html);
        $this->assertStringNotContainsString('Unpublished tour', $html);
        $this->assertStringNotContainsString('No length', $html);
        $this->assertStringNotContainsString('Their tour', $html);
        // The card knows its tour, so its button can choose it.
        $this->assertStringContainsString('data-enquire-listing="'.$this->tour->slug.'"', $html);
    }

    public function test_a_tour_request_lands_on_the_tour_with_its_end_date(): void
    {
        $start = Carbon::today()->addMonth();

        $this->send([
            'listing_id' => $this->tour->id,
            'check_in' => $start->toDateString(),
            // Whatever the browser sends for the end is the tour's to decide.
            'check_out' => $start->copy()->addDays(30)->toDateString(),
            'adults' => 3,
        ])->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertSame($this->tour->id, $inquiry->listing_id);
        $this->assertSame($this->partner->id, $inquiry->partner_id);
        $this->assertSame($start->toDateString(), $inquiry->check_in?->toDateString());
        $this->assertSame($start->copy()->addDays(7)->toDateString(), $inquiry->check_out?->toDateString());
        $this->assertSame(3, $inquiry->adults);
        $this->assertStringStartsWith('Tour: Namibia Top 3 (8 days)', (string) $inquiry->message);
    }

    public function test_a_tailor_made_request_needs_its_own_end_date(): void
    {
        $start = Carbon::today()->addMonth();

        $this->send(['listing_id' => '', 'check_in' => $start->toDateString()])
            ->assertRedirectContains('sent=0');
        $this->assertSame(0, Inquiry::count());

        $this->send(['listing_id' => '', 'check_in' => $start->toDateString(), 'check_out' => $start->copy()->addDays(9)->toDateString()])
            ->assertRedirectContains('sent=1');

        $inquiry = Inquiry::sole();
        $this->assertSame($this->site->source_listing_id, $inquiry->listing_id);
        $this->assertStringStartsWith('Tour: private / tailor-made', (string) $inquiry->message);
    }

    /** A hand-rolled POST naming somebody else's listing is refused, not written. */
    public function test_a_listing_that_is_not_one_of_this_sites_tours_is_refused(): void
    {
        $other = Listing::factory()->create(['duration_minutes' => 1440, 'is_published' => true, 'accepts_inquiries' => true]);

        $this->send(['listing_id' => $other->id, 'check_in' => Carbon::today()->addMonth()->toDateString()])
            ->assertRedirectContains('sent=0');

        $this->assertSame(0, Inquiry::count());
    }
}
