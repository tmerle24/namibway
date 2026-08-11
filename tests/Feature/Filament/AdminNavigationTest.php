<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Filament\Pages\ListingImport;
use App\Filament\Resources\ReleaseNoteResource;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What belongs in the sidebar and in which order.
 *
 * The order is not maintained by hand any more — every item inside a group is
 * sorted on its label by AlphabeticalNavigationManager — so this asserts the
 * navigation the panels actually build, in both panels, rather than the sort
 * numbers that used to stand in for it.
 */
class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_every_admin_group_is_alphabetical(): void
    {
        $this->actingAs($this->admin());

        foreach ($this->sidebarGroups('admin') as $group => $labels) {
            $this->assertNotEmpty($labels, "The {$group} group is empty.");
            $this->assertSame($this->sorted($labels), $labels, "The {$group} group is out of order.");
        }
    }

    public function test_every_partner_group_is_alphabetical(): void
    {
        $partner = Partner::create(['name' => 'Etosha Camp', 'email' => 'camp@example.test']);
        Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Etosha Camp',
        ]);

        $this->actingAs(User::factory()->create(['partner_id' => $partner->id]));

        $groups = $this->sidebarGroups('partner');

        // Guards the loop below against passing on an empty sidebar.
        $this->assertArrayHasKey('Setup', $groups);
        $this->assertContains('Rate plans', $groups['Setup']);

        foreach ($groups as $group => $labels) {
            $this->assertSame($this->sorted($labels), $labels, "The {$group} group is out of order.");
        }
    }

    public function test_amenities_and_api_clients_live_under_settings(): void
    {
        $this->actingAs($this->admin());

        $groups = $this->sidebarGroups('admin');

        $this->assertContains('Amenities', $groups['Settings']);
        $this->assertContains('API Clients', $groups['Settings']);

        // Interfaces held nothing but the API clients screen, so it went with it.
        $this->assertArrayNotHasKey('Interfaces', $groups);
    }

    public function test_the_deploy_log_is_the_only_release_notes_entry(): void
    {
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

    /**
     * The named groups of a panel's sidebar, as labels. The unnamed top-level
     * list is left out on purpose: in the partner panel that is the working day
     * in order (Calendar, Arrivals, Rates), not an alphabet.
     *
     * @return array<string, list<string>>
     */
    private function sidebarGroups(string $panel): array
    {
        // The manager is scoped and reads the current panel in its constructor,
        // so asking a second panel in the same test needs a fresh one.
        $this->app->forgetInstance(NavigationManager::class);
        Filament::setCurrentPanel(Filament::getPanel($panel));

        return collect(Filament::getNavigation())
            ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
            ->mapWithKeys(fn (NavigationGroup $group): array => [
                $group->getLabel() => collect($group->getItems())
                    ->map(fn (NavigationItem $item): string => $item->getLabel())
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private function sorted(array $labels): array
    {
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);

        return $labels;
    }
}
