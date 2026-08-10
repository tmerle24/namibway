<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MarketingMaterial as MarketingMaterialPage;
use App\Models\User;
use App\Support\MarketingMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingMaterialTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_page_lists_every_configured_piece(): void
    {
        $this->actingAs($this->admin());

        $page = Livewire::test(MarketingMaterialPage::class)->assertOk();

        foreach (config('marketing.material') as $item) {
            $page->assertSee($item['title']);
        }
    }

    public function test_admin_downloads_a_flyer(): void
    {
        $this->actingAs($this->admin())
            ->get(route('marketing-material.download', [
                'item' => 'partner-flyer',
                'variant' => 'print',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('namibway-partner-flyer-a4-print.pdf');
    }

    public function test_a_non_admin_cannot_download(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('marketing-material.download', [
                'item' => 'partner-flyer',
                'variant' => 'print',
            ]))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_download(): void
    {
        $this->get(route('marketing-material.download', [
            'item' => 'partner-flyer',
            'variant' => 'print',
        ]))->assertRedirect(route('login'));
    }

    /**
     * The point of resolving through config rather than through the filesystem:
     * whatever a request puts in the URL, it addresses a key or it addresses
     * nothing. These are the strings an attacker would actually try.
     */
    public function test_a_request_cannot_address_a_file_outside_the_material_list(): void
    {
        $this->actingAs($this->admin());

        $attempts = [
            ['item' => '../../.env', 'variant' => 'print'],
            ['item' => 'partner-flyer', 'variant' => '../../../.env'],
            ['item' => 'partner-flyer', 'variant' => 'print/../../../composer.json'],
            ['item' => 'unknown-flyer', 'variant' => 'print'],
            ['item' => 'partner-flyer', 'variant' => 'unknown-variant'],
        ];

        foreach ($attempts as $attempt) {
            $response = $this->get('/admin/marketing-material/'.$attempt['item'].'/'.$attempt['variant']);

            $this->assertContains(
                $response->getStatusCode(),
                [404, 403],
                'Reachable: '.json_encode($attempt),
            );
        }
    }

    public function test_unbuilt_files_are_reported_rather_than_hidden(): void
    {
        config()->set('marketing.material.ghost-flyer', [
            'title' => 'Ghost flyer',
            'audience' => 'Nobody',
            'description' => 'Configured but never built.',
            'basename' => 'namibway-ghost-flyer-a4',
            'source' => 'marketing/flyer-ghost-a4.html',
        ]);

        $ghost = collect(MarketingMaterial::all())->firstWhere('key', 'ghost-flyer');

        $this->assertNotNull($ghost, 'A configured piece should be listed even with no PDF built.');
        $this->assertFalse($ghost['files'][0]['available']);
        $this->assertNull(MarketingMaterial::file('ghost-flyer', 'print'));
    }
}
