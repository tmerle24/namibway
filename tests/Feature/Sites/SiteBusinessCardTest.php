<?php

namespace Tests\Feature\Sites;

use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The digital business card: the page a QR code opens, the contact file it
 * saves, and the code itself.
 */
class SiteBusinessCardTest extends TestCase
{
    use RefreshDatabase;

    private function site(array $attributes = []): Site
    {
        $site = Site::factory()->create(array_merge([
            'name' => 'Epima Tours & Safaris',
            'contact_phone' => '+264 81 356 4713',
            'contact_email' => 'hello@epima.example',
            'whatsapp' => '+264 81 356 4713',
            'address' => "P.O. Box 990\nOtjiwarongo, Namibia",
        ], $attributes));

        SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        return $site;
    }

    private function get_(Site $site, string $path = 'card'): TestResponse
    {
        return $this->get('/_sites/'.$site->slug.'/'.$path.'?preview='.$site->draft_token);
    }

    public function test_the_card_offers_every_way_to_reach_the_business(): void
    {
        $site = $this->site();
        SiteBlock::create(['site_page_id' => $site->pages()->sole()->id, 'type' => 'enquiry',
            'data' => ['heading' => 'Request a quote', 'form_type' => 'contact', 'button_label' => 'Request a quote'], 'sort' => 0]);

        $html = (string) $this->get_($site)->assertOk()->getContent();

        $this->assertStringContainsString('href="tel:+264813564713"', $html);
        $this->assertStringContainsString('https://wa.me/264813564713', $html);
        $this->assertStringContainsString('href="mailto:hello@epima.example"', $html);
        $this->assertStringContainsString('/card/vcf', $html);
        $this->assertStringContainsString('Save contact', $html);
        $this->assertStringContainsString('Request a quote', $html);
        $this->assertStringContainsString('Epima Tours &amp; Safaris', $html);
        // Handed over, not found in a search result.
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_a_channel_the_business_does_not_have_is_not_offered(): void
    {
        $site = $this->site(['whatsapp' => null, 'contact_email' => null, 'contact_phone' => null]);

        $html = (string) $this->get_($site)->assertOk()->getContent();

        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('href="tel:', $html);
        // What is always there: the contact file and the site itself.
        $this->assertStringContainsString('Save contact', $html);
    }

    public function test_the_contact_file_downloads_as_a_vcard(): void
    {
        $listing = Listing::factory()->create(['contact_person' => 'Epimakus Hamutenya']);
        $site = $this->site(['source_listing_id' => $listing->id]);

        $response = $this->get_($site, 'card/vcf')->assertOk();
        $body = (string) $response->getContent();

        $response->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
        $this->assertStringContainsString('attachment; filename="'.$site->slug.'.vcf"', (string) $response->headers->get('Content-Disposition'));

        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0", $body);
        $this->assertStringContainsString('FN:Epimakus Hamutenya', $body);
        $this->assertStringContainsString('ORG:Epima Tours & Safaris', $body);
        // One number, once: phone and WhatsApp are the same line here.
        $this->assertSame(1, substr_count($body, '+264813564713'));
        $this->assertStringContainsString('TEL;TYPE=WORK,VOICE:+264813564713', $body);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET,WORK:hello@epima.example', $body);
        $this->assertStringContainsString('Otjiwarongo', $body);
        $this->assertStringEndsWith("END:VCARD\r\n", $body);
    }

    public function test_the_qr_code_is_an_image_of_the_card_address(): void
    {
        $site = $this->site();

        $response = $this->get_($site, 'card/qr')->assertOk();

        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", (string) $response->getContent());

        $download = $this->get('/_sites/'.$site->slug.'/card/qr?preview='.$site->draft_token.'&download=1');
        $this->assertStringContainsString('attachment;', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_a_draft_card_needs_the_preview_token(): void
    {
        $site = $this->site();

        $this->get('/_sites/'.$site->slug.'/card')->assertNotFound();
        $this->get('/_sites/'.$site->slug.'/card/vcf')->assertNotFound();
        $this->get('/_sites/'.$site->slug.'/card/qr')->assertNotFound();
    }
}
