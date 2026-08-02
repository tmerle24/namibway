<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MessagingSettings;
use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\MessageSettings;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessagingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_page_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/messaging-settings')
            ->assertOk();
    }

    public function test_saving_updates_the_signature(): void
    {
        Livewire::actingAs($this->admin())
            ->test(MessagingSettings::class)
            ->fillForm(['signature' => "Best,\nThe NamibWay Team"])
            ->call('save');

        $this->assertSame("Best,\nThe NamibWay Team", MessageSettings::current()->signature);
    }

    public function test_contact_mail_uses_the_configured_signature_and_pop3_from_address(): void
    {
        config(['services.pop3.username' => 'team@namibway.com']);
        MessageSettings::current()->update(['signature' => "Best,\nThe NamibWay Team"]);

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);

        $mail = new PartnerContactMail($partner, 'Test subject', 'Test body');
        $rendered = $mail->render();

        $this->assertStringContainsString('The NamibWay Team', $rendered);
        $this->assertSame('team@namibway.com', $mail->envelope()->from->address);
    }

    public function test_contact_mail_links_the_specific_listing_when_one_is_given(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123']);
        $listingA = Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge A', 'is_published' => true]);
        Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge B', 'is_published' => true]);

        $rendered = (new PartnerContactMail($partner, 'Subject', 'Body', $listingA))->render();

        $this->assertStringContainsString('Lodge A', $rendered);
        $this->assertStringNotContainsString('Lodge B', $rendered);
    }

    public function test_contact_mail_links_all_listings_when_sent_from_the_partner_level(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123']);
        Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge A', 'is_published' => true]);
        Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge B', 'is_published' => false]);

        $rendered = (new PartnerContactMail($partner, 'Subject', 'Body'))->render();

        $this->assertStringContainsString('Lodge A', $rendered);
        $this->assertStringContainsString('Lodge B', $rendered);
        $this->assertStringContainsString('preview', $rendered);
    }

    public function test_contact_mail_offers_a_claim_link_for_an_unclaimed_partner_with_a_token(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123']);

        $rendered = (new PartnerContactMail($partner, 'Subject', 'Body'))->render();

        $this->assertStringContainsString('Claim it for free', $rendered);
    }

    public function test_contact_mail_has_no_claim_link_once_claimed(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123', 'claimed_at' => now()]);

        $rendered = (new PartnerContactMail($partner, 'Subject', 'Body'))->render();

        $this->assertStringNotContainsString('Claim it for free', $rendered);
    }
}
