<?php

namespace Tests\Feature\Filament;

use App\Livewire\ListingMessagesPanel;
use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ListingPartnerMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_messages_tab_is_hidden_on_the_create_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/listings/create')
            ->assertOk()
            ->assertDontSee('Messages');
    }

    public function test_messages_tab_is_badged_for_a_listing_without_a_partner_email(): void
    {
        $listing = Listing::factory()->create(['partner_id' => null]);

        $this->actingAs($this->admin())
            ->get("/admin/listings/{$listing->id}/edit")
            ->assertOk()
            ->assertSee('Messages')
            ->assertSee('No email');
    }

    public function test_messages_tab_is_not_badged_once_the_partner_has_an_email(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $this->actingAs($this->admin())
            ->get("/admin/listings/{$listing->id}/edit")
            ->assertOk()
            ->assertSee('Messages')
            ->assertDontSee('No email');
    }

    public function test_contact_owner_action_sends_mail_and_logs_message(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(ListingMessagesPanel::class, ['record' => $listing])
            ->callTableAction('contact_owner', data: [
                'subject' => 'Test subject',
                'body' => 'Test body',
            ]);

        Mail::assertQueued(PartnerContactMail::class, fn ($mail) => $mail->hasTo($partner->email));

        $this->assertDatabaseHas('partner_messages', [
            'partner_id' => $partner->id,
            'listing_id' => $listing->id,
            'subject' => 'Test subject',
        ]);
    }

    public function test_send_claim_email_action_is_hidden_once_claimed(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claimed_at' => now()]);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(ListingMessagesPanel::class, ['record' => $listing])
            ->assertTableActionHidden('send_claim_email');
    }

    public function test_send_claim_email_action_sends_invite_and_logs_message(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(ListingMessagesPanel::class, ['record' => $listing])
            ->callTableAction('send_claim_email');

        $this->assertDatabaseHas('partner_messages', [
            'partner_id' => $partner->id,
            'template' => 'claim_invite',
        ]);
    }

    public function test_opening_the_tab_marks_unread_inbound_messages_as_read(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $message = PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listing->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'Re: your listing',
            'body' => 'Sounds good.',
            'sent_at' => now(),
        ]);

        $this->assertNull($message->fresh()->read_at);

        Livewire::actingAs($this->admin())
            ->test(ListingMessagesPanel::class, ['record' => $listing]);

        $this->assertNotNull($message->fresh()->read_at);
    }
}
