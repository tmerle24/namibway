<?php

namespace Tests\Feature\Filament;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HasFormActionsInHeader/HasCreateFormActionsInHeader move the Save/Create
 * submit button into the page header, outside the record's own <form>.
 * Filament's stock submit actions don't set an explicit `form` attribute
 * (they assume they're rendered *inside* the form), so without one, a
 * header-relocated submit button is a <button type="submit"> attached to
 * nothing — clicking it, or pressing Enter in a field, does nothing at all,
 * with no visible error. This guards against that regression recurring.
 */
class HeaderFormActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array<int, string>
     */
    private function submitButtonFormAttributes(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $attributes = [];

        foreach ($xpath->query('//button[@type="submit"]') as $button) {
            $attributes[] = $button->getAttribute('form');
        }

        return $attributes;
    }

    public function test_listing_edit_submit_button_is_linked_to_the_form(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);
        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $html = $this->actingAs($this->admin())
            ->get("/admin/listings/{$listing->id}/edit")
            ->getContent();

        $this->assertContains('form', $this->submitButtonFormAttributes($html));
    }

    public function test_listing_create_submit_button_is_linked_to_the_form(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/listings/create')
            ->getContent();

        $this->assertContains('form', $this->submitButtonFormAttributes($html));
    }

    public function test_partner_edit_submit_button_is_linked_to_the_form(): void
    {
        $partner = Partner::create(['name' => 'Lodge', 'email' => 'owner@example.com']);

        $html = $this->actingAs($this->admin())
            ->get("/admin/partners/{$partner->id}/edit")
            ->getContent();

        $this->assertContains('form', $this->submitButtonFormAttributes($html));
    }
}
