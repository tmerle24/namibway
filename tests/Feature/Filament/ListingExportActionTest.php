<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Filament\Resources\ListingResource\Pages\ListListings;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The export half of the bulk capture loop: the workbook the listings table hands
 * out is what comes back through the importer, so it has to carry the id column
 * and respect the table's filters.
 */
class ListingExportActionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function listing(string $name, ListingType $type): Listing
    {
        $partner = Partner::create(['name' => 'Test Partner '.uniqid()]);

        return Listing::factory()->create([
            'partner_id' => $partner->id,
            'name' => $name,
            'type' => $type,
        ]);
    }

    public function test_the_table_action_hands_out_a_workbook(): void
    {
        $admin = $this->admin();
        $this->listing('Okonjima Bush Camp', ListingType::Accommodation);

        $component = Livewire::actingAs($admin)
            ->test(ListListings::class)
            ->callTableAction('export_excel');

        $url = data_get($component->effects, 'redirect');
        $this->assertIsString($url);

        $this->actingAs($admin)->get($url)->assertOk()->assertDownload();
    }

    public function test_the_template_action_hands_out_an_empty_workbook(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(ListListings::class)
            ->callTableAction('export_template');

        $url = data_get($component->effects, 'redirect');
        $this->assertIsString($url);

        $this->actingAs($admin)->get($url)->assertOk()->assertDownload('listings-vorlage.xlsx');
    }

    public function test_the_download_link_is_single_use_and_admins_only(): void
    {
        $admin = $this->admin();

        $component = Livewire::actingAs($admin)
            ->test(ListListings::class)
            ->callTableAction('export_template');

        $url = (string) data_get($component->effects, 'redirect');

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertForbidden();

        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($admin)->get($url)->assertNotFound();
    }
}
