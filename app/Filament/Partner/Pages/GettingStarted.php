<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Support\PropertySetup;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Services\Booking\BookingMailbox;
use Filament\Pages\Page;

/**
 * What state this property is in, what is left to set up, and who to ask.
 *
 * The page exists because of one question the panel could not answer: an
 * operator reads that their bookings are not live yet and has nowhere to find
 * out how that changes. "Contact us", written into a banner, is not an answer.
 * Which of the three states the property is in, what each does with the mail,
 * and what is still missing from the setup — that is.
 *
 * Everything on it is derived, never stored: the checklist reads the same
 * tables the screens do, so it cannot claim a step is done that is not, and
 * nobody has to tick anything off.
 */
class GettingStarted extends Page
{
    protected static string $view = 'filament.partner.pages.getting-started';

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Getting started';

    protected static ?string $navigationGroup = 'Setup';

    protected static ?string $title = 'Getting started';

    public static function shouldRegisterNavigation(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $property = $this->property();
        $setup = app(PropertySetup::class);

        return [
            'property' => $property,
            'steps' => $setup->steps($property),
            'state' => $property === null ? null : $setup->bookingState($property),
            'mailbox' => $property === null ? null : new BookingMailbox($property),
            'supportAddress' => (string) config('booking.team_address', 'team@namibway.com'),
        ];
    }

    private function property(): ?Listing
    {
        return app(SelectedProperty::class)->current();
    }
}
