<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\PaymentsGuide;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The payments guide is the page a member of staff opens with a lodge owner on
 * the phone, so what it has to keep is not layout but *content*: the three
 * settlement models by name, who sets which rate, where each setup step
 * happens, and the claims we may not make.
 *
 * A page that renders and has quietly lost the sentence about VAT is worse
 * than one that fails, because somebody will read what is left and answer the
 * question wrongly. Hence assertions on the words.
 *
 * Those assertions run against **whitespace-normalised text**, not the raw
 * HTML. A sentence in a Blade file wraps wherever the line got long, so a
 * literal substring match would fail on a phrase that happens to straddle two
 * lines — and reflowing prose to keep a test green is the tail wagging the
 * dog.
 */
class PaymentsGuideTest extends TestCase
{
    use RefreshDatabase;

    private function guideText(): string
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::test(PaymentsGuide::class)->assertOk()->html();

        // Tags out, entities back to characters, runs of whitespace to one
        // space. What is left is what a reader actually sees.
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function assertGuideSays(array $phrases): void
    {
        $text = $this->guideText();

        foreach ($phrases as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $text,
                "The payments guide no longer says: [{$phrase}]. Somebody answering a partner from this page would now get it wrong.",
            );
        }
    }

    public function test_the_guide_renders_for_staff(): void
    {
        $this->assertNotSame('', trim($this->guideText()));
    }

    public function test_it_names_all_three_settlement_models_and_the_number_that_picks_them(): void
    {
        $this->assertGuideSays([
            'Agency',
            'Split',
            'Merchant',
            // The whole mechanic in one line. Without it somebody goes looking
            // for a settlement-model dropdown that does not exist.
            'one number',
        ]);
    }

    public function test_it_says_who_owns_each_rate_and_what_commission_is_taken_on(): void
    {
        $this->assertGuideSays([
            'NamibWay only',
            'The partner',
            // The claim an operator will test on the phone.
            'before VAT and the tourism levy',
            'The partner panel has no commission field at all',
        ]);
    }

    public function test_it_tells_staff_where_every_setup_step_happens(): void
    {
        $this->assertGuideSays([
            'Commission and deposits',
            'What they bought',
            'May collect no deposit at all',
            'Deposit and commission',
            // The gateway is the one step that is in no panel at all, which is
            // exactly what somebody will hunt for and fail to find.
            'PAYMENTS_PROVIDER',
            'DPO_COMPANY_TOKEN',
        ]);
    }

    public function test_it_carries_the_claims_we_may_not_make(): void
    {
        $this->assertGuideSays([
            'no merchant account yet',
            'Real software on made-up data',
            'A commission percentage in print',
        ]);
    }

    /**
     * The question a partner asks first when a payment link is missing, and
     * the answer is "that is correct" rather than "that is a bug".
     */
    public function test_it_explains_why_an_older_booking_has_no_payment_link(): void
    {
        $this->assertGuideSays(['frozen when a booking is taken']);
    }

    public function test_it_appears_under_documentation_in_the_admin_sidebar(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $labels = [];

        foreach (Filament::getNavigation() as $group) {
            if ($group->getLabel() !== 'Documentation') {
                continue;
            }

            foreach ($group->getItems() as $item) {
                $labels[] = $item->getLabel();
            }
        }

        $this->assertContains('Payments Guide', $labels);
    }

    /** Staff only. A partner has no business reading our claims policy. */
    public function test_it_is_not_reachable_from_the_partner_panel(): void
    {
        $this->assertNotContains(
            PaymentsGuide::class,
            Filament::getPanel('partner')->getPages(),
        );
    }
}
