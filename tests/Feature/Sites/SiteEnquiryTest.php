<?php

namespace Tests\Feature\Sites;

use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Blocks\EnquiryBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The enquiry form on a customer's own website.
 *
 * It is the one thing on these pages that writes, and it writes into the
 * pipeline that already exists: the same `Inquiry` the travel platform creates,
 * so the partner gets the mail with the signed confirm and decline links and
 * the guest gets an answer either way. What is asserted here is that a stay
 * and a visit are asked different questions, that the outcome survives a page
 * that has no session, and that neither a bot nor a forged referer gets
 * anything out of it.
 */
class SiteEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private function siteFor(Listing $listing, string $mode = EnquiryBlock::MODE_STAY): Site
    {
        $site = Site::factory()->create([
            'source_listing_id' => $listing->id,
            'slug' => 'enquiry-site',
        ]);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'enquiry',
            'data' => ['heading' => 'Ask us', 'mode' => $mode],
            'sort' => 0,
        ]);

        return $site;
    }

    private function listing(array $attributes = []): Listing
    {
        return Listing::factory()->create($attributes + ['accepts_inquiries' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Anna Weber',
            'email' => 'anna@example.com',
            'check_in' => Carbon::today()->addMonth()->toDateString(),
            'check_out' => Carbon::today()->addMonth()->addDays(3)->toDateString(),
            'website' => '',
        ];
    }

    public function test_an_enquiry_from_a_site_creates_an_inquiry_on_its_listing(): void
    {
        $listing = $this->listing();
        $site = $this->siteFor($listing);

        $this->post("/_sites/{$site->slug}/enquiry", $this->payload())
            ->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertSame($listing->id, $inquiry->listing_id);
        $this->assertSame('anna@example.com', $inquiry->email);
        $this->assertNotNull($inquiry->check_out);
    }

    public function test_a_visit_is_asked_for_a_time_and_never_a_departure(): void
    {
        $listing = $this->listing();
        $site = $this->siteFor($listing, EnquiryBlock::MODE_VISIT);

        $this->get($site->publicUrl())
            ->assertSee('name="time"', false)
            ->assertDontSee('name="check_out"', false);

        $this->post("/_sites/{$site->slug}/enquiry", [
            'name' => 'Dinner Guest',
            'email' => 'dinner@example.com',
            'check_in' => Carbon::today()->addWeek()->toDateString(),
            'time' => '19:30',
        ])->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertNull($inquiry->check_out);
        $this->assertStringContainsString('19:30', (string) $inquiry->travel_dates);
    }

    public function test_a_filled_honeypot_is_answered_like_a_success_and_writes_nothing(): void
    {
        $site = $this->siteFor($this->listing());

        $this->post("/_sites/{$site->slug}/enquiry", $this->payload(['website' => 'http://spam.example']))
            ->assertRedirect();

        $this->assertSame(0, Inquiry::count());
    }

    /**
     * These pages carry no session, so `withErrors()` has nowhere to flash to.
     * The outcome rides in the query string instead, and the form reads it.
     */
    public function test_a_rejected_enquiry_comes_back_marked_and_the_form_says_so(): void
    {
        $site = $this->siteFor($this->listing());

        $response = $this->post("/_sites/{$site->slug}/enquiry", $this->payload(['email' => 'not-an-address']));

        $response->assertRedirect();
        $this->assertStringContainsString('sent=0', (string) $response->headers->get('location'));
        $this->assertSame(0, Inquiry::count());
    }

    /**
     * A referer is whatever the client says it is. Sending the visitor onwards
     * to it would be an open redirect on every customer's domain.
     */
    public function test_an_offsite_referer_is_not_redirected_to(): void
    {
        $site = $this->siteFor($this->listing());

        $response = $this->post(
            "/_sites/{$site->slug}/enquiry",
            $this->payload(),
            ['referer' => 'https://evil.example/collect']
        );

        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('location'));
    }

    public function test_a_listing_that_takes_no_enquiries_has_no_form_and_no_endpoint(): void
    {
        $listing = $this->listing(['accepts_inquiries' => false]);
        $site = $this->siteFor($listing);

        $this->get($site->publicUrl())->assertDontSee('name="check_in"', false);

        $this->post("/_sites/{$site->slug}/enquiry", $this->payload())->assertNotFound();
    }
}
