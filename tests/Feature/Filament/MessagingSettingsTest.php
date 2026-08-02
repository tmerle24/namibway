<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MessagingSettings;
use App\Mail\PartnerContactMail;
use App\Models\MessageSettings;
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

        $mail = new PartnerContactMail('Test subject', 'Test body');
        $rendered = $mail->render();

        $this->assertStringContainsString('The NamibWay Team', $rendered);
        $this->assertSame('team@namibway.com', $mail->envelope()->from->address);
    }
}
