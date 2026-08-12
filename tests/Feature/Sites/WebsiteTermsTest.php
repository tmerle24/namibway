<?php

namespace Tests\Feature\Sites;

use App\Mail\SiteTermsConfirmation;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Models\WebsiteTerms;
use App\Sites\LegalText;
use App\Sites\WebsiteTermsText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Our own terms, and the business agreeing to them.
 *
 * Two things are being protected. A draft must never be what a customer is
 * shown — the scaffold describes how the product works and has not been read by
 * a lawyer, so an unpublished version is not a document anybody has agreed to.
 * And the acceptance has to say *which* text was accepted, or it says nothing
 * the first time the terms change.
 */
class WebsiteTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // The env var wins over the published version, which is how a text
        // hosted elsewhere is named without a deploy. Cleared here so these
        // tests exercise the page this application serves.
        config(['sites.terms_url' => '']);
    }

    public function test_nothing_is_linked_or_served_while_no_version_is_published(): void
    {
        WebsiteTerms::scaffoldIfEmpty();

        $this->assertNull(LegalText::termsUrl());
        $this->get('/website-terms')->assertNotFound();
    }

    public function test_a_published_version_is_served_and_linked(): void
    {
        WebsiteTerms::scaffoldIfEmpty()->forceFill(['published_at' => now()])->save();

        $this->assertSame(route('website.terms'), LegalText::termsUrl());

        $this->get('/website-terms')
            ->assertOk()
            ->assertSee('Website terms')
            ->assertSee(WebsiteTermsText::VERSION, false);
    }

    public function test_the_most_recent_published_version_is_the_one_in_force(): void
    {
        WebsiteTerms::create([
            'version' => '1.0',
            'body' => '<p>The old one.</p>',
            'published_at' => now()->subYear(),
        ]);

        WebsiteTerms::create([
            'version' => '2.0',
            'body' => '<p>The new one.</p>',
            'published_at' => now(),
        ]);

        $this->get('/website-terms')->assertOk()->assertSee('The new one.', false);
    }

    /**
     * A mail client that prefetches links must not be able to publish somebody's
     * website by opening the message.
     */
    public function test_the_emailed_link_only_shows_the_page(): void
    {
        $site = $this->site();

        $this->get($this->confirmUrl($site))->assertOk()->assertSee('Confirm and publish');

        $this->assertNull($site->refresh()->terms_accepted_at);
        $this->assertFalse($site->isPublished());
    }

    public function test_confirming_records_who_when_and_which_version_and_publishes(): void
    {
        WebsiteTerms::scaffoldIfEmpty()->forceFill(['published_at' => now()])->save();

        $site = $this->site();

        $this->post($this->confirmUrl($site), ['confirmed_by' => 'Anna Shipanga'])->assertOk();

        $site->refresh();

        $this->assertNotNull($site->terms_accepted_at);
        $this->assertSame('Anna Shipanga', $site->terms_accepted_by);
        $this->assertSame(WebsiteTermsText::VERSION, $site->terms_version);
        $this->assertTrue($site->isPublished());
    }

    public function test_an_unsigned_address_confirms_nothing(): void
    {
        $site = $this->site();

        $this->post("/sites/{$site->id}/confirm", ['confirmed_by' => 'Anybody'])->assertForbidden();

        $this->assertNull($site->refresh()->terms_accepted_at);
    }

    public function test_a_confirmation_already_given_is_not_overwritten(): void
    {
        $site = $this->site();

        $this->post($this->confirmUrl($site), ['confirmed_by' => 'Anna Shipanga'])->assertOk();

        $first = $site->refresh()->terms_accepted_at;

        $this->post($this->confirmUrl($site), ['confirmed_by' => 'Somebody Else'])->assertOk();

        $site->refresh();

        $this->assertSame('Anna Shipanga', $site->terms_accepted_by);
        $this->assertEquals($first, $site->terms_accepted_at);
    }

    public function test_the_email_carries_a_signed_link_and_the_address_of_the_site(): void
    {
        $site = $this->site();

        Mail::to('owner@example.com')->send(new SiteTermsConfirmation($site));

        Mail::assertQueued(SiteTermsConfirmation::class, function (SiteTermsConfirmation $mail): bool {
            return str_contains($mail->confirmUrl, '/confirm')
                && str_contains($mail->confirmUrl, 'signature=');
        });
    }

    private function confirmUrl(Site $site): string
    {
        return URL::signedRoute('sites.terms.confirm', ['site' => $site->id], now()->addDays(30));
    }

    private function site(): Site
    {
        $listing = Listing::factory()->create();

        $site = Site::factory()->create([
            'source_listing_id' => $listing->id,
            'slug' => 'dune-edge',
            'contact_email' => 'owner@example.com',
        ]);

        $page = SitePage::factory()->create([
            'site_id' => $site->id,
            'is_home' => true,
            'slug' => '',
            'locale' => $site->default_locale,
        ]);

        // A site with nothing on it cannot be published, and the point of these
        // tests is what happens when it can.
        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'about',
            'data' => ['heading' => 'About us', 'body' => '<p>Words.</p>'],
            'sort' => 0,
        ]);

        return $site;
    }
}
