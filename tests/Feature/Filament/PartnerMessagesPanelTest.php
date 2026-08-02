<?php

namespace Tests\Feature\Filament;

use App\Livewire\PartnerMessagesPanel;
use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PartnerMessagesPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_messages_tab_is_hidden_on_the_create_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/partners/create')
            ->assertOk()
            ->assertDontSee('Messages');
    }

    public function test_shows_the_cumulated_thread_across_more_than_one_listing(): void
    {
        $partner = Partner::create(['name' => 'Multi Lodge', 'email' => 'owner@example.com']);
        $listingA = Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge A']);
        $listingB = Listing::factory()->create(['partner_id' => $partner->id, 'name' => 'Lodge B']);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listingA->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'About Lodge A',
            'body' => 'Hi',
            'sent_at' => now(),
        ]);

        PartnerMessage::create([
            'partner_id' => $partner->id,
            'listing_id' => $listingB->id,
            'direction' => PartnerMessage::DIRECTION_INBOUND,
            'subject' => 'About Lodge B',
            'body' => 'Hi',
            'sent_at' => now(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(PartnerMessagesPanel::class, ['record' => $partner])
            ->assertCanSeeTableRecords(PartnerMessage::all());
    }

    public function test_contact_owner_action_sends_mail_and_logs_message_without_a_listing_id(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        Listing::factory()->create(['partner_id' => $partner->id]);
        Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(PartnerMessagesPanel::class, ['record' => $partner])
            ->callTableAction('contact_owner', data: [
                'subject' => 'Test subject',
                'body' => 'Test body',
            ]);

        Mail::assertQueued(PartnerContactMail::class, fn ($mail) => $mail->hasTo($partner->email));

        $this->assertDatabaseHas('partner_messages', [
            'partner_id' => $partner->id,
            'listing_id' => null,
            'subject' => 'Test subject',
        ]);
    }

    public function test_send_claim_email_action_is_hidden_once_claimed(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claimed_at' => now()]);

        Livewire::actingAs($this->admin())
            ->test(PartnerMessagesPanel::class, ['record' => $partner])
            ->assertTableActionHidden('send_claim_email');
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
            ->test(PartnerMessagesPanel::class, ['record' => $partner]);

        $this->assertNotNull($message->fresh()->read_at);
    }
}
