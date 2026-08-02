<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ListingResource\Pages\EditListing;
use App\Filament\Resources\ListingResource\RelationManagers\PartnerMessagesRelationManager;
use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\Partner;
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

    public function test_messages_tab_is_hidden_for_a_listing_without_a_partner(): void
    {
        $listing = Listing::factory()->create(['partner_id' => null]);

        $this->assertFalse(
            PartnerMessagesRelationManager::canViewForRecord($listing, EditListing::class)
        );
    }

    public function test_edit_page_renders_with_messages_tab_for_a_listing_with_a_partner(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $this->actingAs($this->admin())
            ->get("/admin/listings/{$listing->id}/edit")
            ->assertOk()
            ->assertSee('Messages');
    }

    public function test_contact_owner_action_sends_mail_and_logs_message(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(PartnerMessagesRelationManager::class, [
                'ownerRecord' => $listing,
                'pageClass' => EditListing::class,
            ])
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
            ->test(PartnerMessagesRelationManager::class, [
                'ownerRecord' => $listing,
                'pageClass' => EditListing::class,
            ])
            ->assertTableActionHidden('send_claim_email');
    }

    public function test_send_claim_email_action_sends_invite_and_logs_message(): void
    {
        Mail::fake();

        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com', 'claim_token' => 'tok123']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        Livewire::actingAs($this->admin())
            ->test(PartnerMessagesRelationManager::class, [
                'ownerRecord' => $listing,
                'pageClass' => EditListing::class,
            ])
            ->callTableAction('send_claim_email');

        $this->assertDatabaseHas('partner_messages', [
            'partner_id' => $partner->id,
            'template' => 'claim_invite',
        ]);
    }
}
