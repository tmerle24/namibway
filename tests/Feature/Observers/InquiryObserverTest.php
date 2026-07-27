<?php

namespace Tests\Feature\Observers;

use App\Enums\ConnectorType;
use App\Enums\InquiryStatus;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_nwr_listing_inquiry_gets_nwr_pending_status(): void
    {
        $partner = Partner::create([
            'name' => 'NWR Etosha',
            'connector_type' => ConnectorType::Nwr->value,
        ]);

        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Klaus Meier',
            'email' => 'klaus@example.com',
            'travel_dates' => '1–5 Dec 2026',
        ]);

        $this->assertEquals(InquiryStatus::NwrPending, $inquiry->status);
    }

    public function test_non_nwr_listing_inquiry_gets_pending_status(): void
    {
        $partner = Partner::create([
            'name' => 'Desert Lodge',
            'connector_type' => ConnectorType::ResConnect->value,
        ]);

        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Anna Schmidt',
            'email' => 'anna@example.com',
            'travel_dates' => '10–15 Jan 2027',
        ]);

        $this->assertEquals(InquiryStatus::Pending, $inquiry->status);
    }

    public function test_listing_without_partner_gets_pending_status(): void
    {
        $listing = Listing::factory()->create(['partner_id' => null]);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Test Guest',
            'email' => 'test@example.com',
        ]);

        $this->assertEquals(InquiryStatus::Pending, $inquiry->status);
    }

    public function test_explicit_status_is_not_overwritten(): void
    {
        $partner = Partner::create([
            'name' => 'NWR Sossusvlei',
            'connector_type' => ConnectorType::Nwr->value,
        ]);

        $listing = Listing::factory()->create(['partner_id' => $partner->id]);

        $inquiry = Inquiry::create([
            'listing_id' => $listing->id,
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'status' => InquiryStatus::Confirmed,
        ]);

        $this->assertEquals(InquiryStatus::Confirmed, $inquiry->status);
    }
}
