<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Pages\Concerns\ShowsReservationDetail;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\OccupancyGrid;
use App\Support\CountrySettings;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Room types down, nights across, stays and blocks as bars — the shape a lodge
 * already has on the wall.
 *
 * Read only in this slice: no creating a booking, no moving a bar, no editing
 * a rate. Everything the screen can do, it can do to the book without changing
 * it, which is why nothing here touches InventoryWriter.
 *
 * The data comes out of OccupancyGrid as plain objects, already clipped and
 * lane-packed, so the view does no date arithmetic and issues no queries.
 */
class OccupancyCalendar extends Page
{
    use ShowsReservationDetail;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Occupancy calendar';

    protected static ?string $slug = 'calendar';

    protected static ?int $navigationSort = -2;

    protected static string $view = 'filament.partner.pages.occupancy-calendar';

    /**
     * In the URL so a refresh, a bookmark or a link pasted to a colleague all
     * land on the same weeks.
     */
    #[Url(as: 'from')]
    public ?string $from = null;

    /** How far the arrows move: half a screen, so context is never lost. */
    private const STEP_DAYS = 14;

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
        $this->from = null;
        $this->closeReservation();
    }

    public function shift(int $days): void
    {
        $this->from = $this->start()->addDays($days)->toDateString();
        $this->closeReservation();
    }

    public function grid(): ?OccupancyGridData
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        return app(OccupancyGrid::class)->build($property, $this->start(), OccupancyGrid::DEFAULT_DAYS);
    }

    /**
     * The first night on screen. A property's own date, not the server's — a
     * lodge in Windhoek turning over at midnight is not doing it in UTC.
     *
     * A `from` that cannot be parsed falls back to today rather than throwing:
     * the query string is user input, and a mistyped URL should show the
     * calendar, not an error page.
     */
    private function start(): Carbon
    {
        $property = $this->property();
        $today = $property === null
            ? Carbon::now()->startOfDay()
            : CountrySettings::for($property)->today();

        if (blank($this->from)) {
            return $today;
        }

        try {
            return Carbon::parse($this->from)->startOfDay();
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
            'grid' => $this->grid(),
            'property' => $this->property(),
            'stepDays' => self::STEP_DAYS,
        ];
    }
}
