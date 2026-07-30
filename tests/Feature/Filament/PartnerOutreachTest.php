<?php

namespace Tests\Feature\Filament;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerOutreachTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_partner_list_page_renders_for_an_admin(): void
    {
        Partner::create(['name' => 'Scraped Lodge', 'email' => 'lodge@example.com', 'claim_token' => 'abc']);

        $this->actingAs($this->admin())
            ->get('/admin/partners')
            ->assertOk()
            ->assertSee('Scraped Lodge');
    }

    public function test_listing_list_page_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/listings')
            ->assertOk();
    }
}
