<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Pages\Concerns\ShowsReservationDetail;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Services\Inventory\ArrivalsBoard;
use App\Services\Inventory\DTOs\ArrivalsBoardData;
use App\Support\CountrySettings;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The screen a lodge opens every morning: who arrives, who leaves, who stays.
 *
 * Three plain sections rather than three Filament tables — this one gets
 * printed and carried to a desk, and pagination, search and sort controls do
 * not survive a printer. Read only, like the calendar.
 */
class ArrivalsDepartures extends Page
{
    use ShowsReservationDetail;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static ?string $navigationLabel = 'Arrivals';

    protected static ?string $title = 'Arrivals and departures';

    protected static ?string $slug = 'arrivals';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.partner.pages.arrivals-departures';

    #[Url(as: 'date')]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    public function getSubheading(): ?string
    {
        return $this->property()?->name;
    }

    public function today(): void
    {
        $this->date = null;
        $this->closeReservation();
    }

    public function shift(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
        $this->closeReservation();
    }

    public function board(): ?ArrivalsBoardData
    {
        $property = $this->property();

        return $property === null ? null : app(ArrivalsBoard::class)->forDate($property, $this->day());
    }

    /** The property's own date — see OccupancyCalendar::start() for why. */
    private function day(): Carbon
    {
        $property = $this->property();
        $today = $property === null
            ? Carbon::now()->startOfDay()
            : CountrySettings::for($property)->today();

        if (blank($this->date)) {
            return $today;
        }

        try {
            return Carbon::parse($this->date)->startOfDay();
        } catch (InvalidFormatException) {
            return $today;
        }
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
            'board' => $this->board(),
            'property' => $this->property(),
        ];
    }
}
