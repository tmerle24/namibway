<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ApiSetupGuide;
use App\Filament\Pages\BookingConnectorGuide;
use App\Filament\Pages\BulkCaptureGuide;
use App\Filament\Pages\ListingImport;
use App\Filament\Pages\ListingsPartnerHandbook;
use App\Filament\Pages\MarketingMaterial;
use App\Filament\Pages\ReleaseNotes;
use App\Filament\Resources\ReleaseNoteResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What belongs in the admin sidebar and in which order. The Documentation group
 * is sorted by hand (Filament orders on navigationSort, not on the label), so
 * the alphabetical promise needs a test or the next added page silently breaks it.
 */
class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_documentation_group_is_alphabetical(): void
    {
        $pages = [
            ApiSetupGuide::class,
            BookingConnectorGuide::class,
            BulkCaptureGuide::class,
            ListingsPartnerHandbook::class,
            MarketingMaterial::class,
            ReleaseNotes::class,
        ];

        $labels = collect($pages)
            // "API Documentation" (sort 1) is a NavigationItem in AdminPanelProvider,
            // not a page, so it isn't in this list — it just leads the alphabet.
            ->mapWithKeys(fn (string $page): array => [$page::getNavigationLabel() => $page::getNavigationSort()])
            ->sort()
            ->keys()
            ->all();

        $alphabetical = $labels;
        sort($alphabetical, SORT_NATURAL | SORT_FLAG_CASE);

        $this->assertSame($alphabetical, $labels);
    }

    public function test_the_deploy_log_is_the_only_release_notes_entry(): void
    {
        $this->assertSame('Release Notes', ReleaseNotes::getNavigationLabel());
        $this->assertFalse(ReleaseNoteResource::shouldRegisterNavigation());

        // Renamed, not moved: the URL and its bookmarks stay.
        $this->actingAs($this->admin())
            ->get('/admin/deploy-log')
            ->assertOk()
            ->assertSee('Release Notes');
    }

    public function test_the_import_page_is_reached_from_the_listings_table_not_the_sidebar(): void
    {
        $this->assertFalse(ListingImport::shouldRegisterNavigation());

        $this->actingAs($this->admin())
            ->get('/admin/listings')
            ->assertOk()
            ->assertSee('Import listings')
            ->assertSee(ListingImport::getUrl(), escape: false);
    }
}
