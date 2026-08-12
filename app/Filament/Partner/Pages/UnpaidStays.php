<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Pages\Concerns\EditsInventory;
use App\Filament\Partner\Pages\Concerns\IssuesInvoices;
use App\Filament\Partner\Pages\Concerns\RecordsPayments;
use App\Filament\Partner\Pages\Concerns\ShowsReservationDetail;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Models\Reservation;
use App\Services\Payments\Folio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every stay at this property that is not square, soonest arrival first.
 *
 * The other half of the arrivals board. That screen answers "who is here
 * today"; this one answers "who owes us something", which is the question
 * somebody asks once a week rather than every morning — hence its own page
 * and not another section on the board.
 *
 * A plain list rather than a Filament table, like the two screens beside it:
 * this gets printed and carried to an office, and pagination and sort controls
 * do not survive a printer.
 *
 * Read only. Money is taken through the same drawer the calendar and the board
 * open, so there is exactly one place a payment is entered.
 */
class UnpaidStays extends Page implements HasForms
{
    // The stay drawer is one shared partial across all three lodge screens,
    // and it offers the lifecycle buttons wherever it opens — a guest who is
    // paying at the counter is often checking in at the same moment. So this
    // page carries the same two traits the calendar and the board do.
    use EditsInventory;
    use InteractsWithForms;
    use IssuesInvoices;
    use RecordsPayments;
    use ShowsReservationDetail;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Unpaid';

    protected static ?string $title = 'Unpaid stays';

    protected static ?string $slug = 'unpaid';

    /**
     * After the arrivals board and before the rates screen: the ungrouped
     * top-level items are the working day in sequence rather than a lookup, so
     * they are the one place in either panel that still sorts by hand. See
     * CLAUDE.md, "Panel navigation".
     */
    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.partner.pages.unpaid-stays';

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /** A wide table and a print target — see ArrivalsDepartures. */
    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    public function getSubheading(): ?string
    {
        return $this->property()?->name;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function stays(): Collection
    {
        $property = $this->property();

        /** @var Collection<int, Reservation> $empty */
        $empty = new Collection;

        return $property === null ? $empty : app(Folio::class)->outstandingFor($property);
    }

    protected function property(): ?Listing
    {
        return app(SelectedProperty::class)->current();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'stays' => $this->stays(),
            'property' => $this->property(),
        ];
    }
}
