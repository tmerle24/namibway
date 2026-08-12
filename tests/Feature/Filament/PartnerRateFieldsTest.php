<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Filament\Pages\CommissionSettings;
use App\Filament\Partner\Resources\ListingResource\Pages\EditListing;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PaymentSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Commission is ours and the deposit is the partner's — PAYMENTS.md § 2a.
 *
 * The load-bearing test here is the second one. "The field is not on the
 * screen" is a UI decision somebody can undo by accident; "posting the field
 * changes nothing" is the actual guarantee, and it holds because
 * `commission_rate` is not in the partner panel's form schema at all, so
 * Filament has nothing to hydrate it into.
 */
class PartnerRateFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partner_can_set_their_own_deposit(): void
    {
        PaymentSettings::current()->update(['commission_rate' => 5.0, 'minimum_deposit_rate' => 10.0]);

        [$user, $listing] = $this->partnerWithProperty();

        $this->asPartner($user);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['deposit_rate' => 25.0])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(25.0, $listing->fresh()?->deposit_rate);
    }

    public function test_a_partner_cannot_set_a_deposit_below_the_floor(): void
    {
        PaymentSettings::current()->update(['commission_rate' => 5.0, 'minimum_deposit_rate' => 15.0]);

        [$user, $listing] = $this->partnerWithProperty();

        $this->asPartner($user);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['deposit_rate' => 2.0])
            ->call('save')
            ->assertHasFormErrors(['deposit_rate']);

        $this->assertNull($listing->fresh()?->deposit_rate);
    }

    /**
     * Not "the input is hidden" — the field does not exist in this form, so a
     * crafted request has nothing to write through.
     */
    public function test_the_partner_panel_offers_no_way_to_change_the_commission(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $listing->update(['commission_rate' => 5.0]);

        $this->asPartner($user);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertFormFieldDoesNotExist('commission_rate')
            ->fillForm(['deposit_rate' => 30.0])
            // Posting the field directly, which is the attack the hidden-input
            // version of this test would not catch.
            ->set('data.commission_rate', 0.1)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5.0, $listing->fresh()?->commission_rate);
    }

    public function test_a_partner_can_see_what_they_pay(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $listing->update(['commission_rate' => 7.5]);

        $this->asPartner($user);

        Livewire::test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->assertSee('7.5%');
    }

    public function test_the_platform_rates_are_edited_in_the_admin_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CommissionSettings::class)
            ->fillForm([
                'commission_rate' => 6.5,
                'deposit_rate' => 20.0,
                'minimum_deposit_rate' => null,
            ])
            ->call('save');

        $settings = PaymentSettings::current()->fresh();

        $this->assertSame(6.5, $settings?->commission_rate);
        $this->assertSame(20.0, $settings?->deposit_rate);
        // Empty means "follow the commission" rather than "zero".
        $this->assertNull($settings?->minimum_deposit_rate);
        $this->assertSame(6.5, $settings?->effectiveMinimumDepositRate());
    }

    /*
    |--------------------------------------------------------------------------
    | Plumbing
    |--------------------------------------------------------------------------
    */

    private function asPartner(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(): array
    {
        $partner = Partner::create(['name' => 'Etosha Camp', 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Etosha Camp',
        ]);

        return [$user, $listing];
    }
}
