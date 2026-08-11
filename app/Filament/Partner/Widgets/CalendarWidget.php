<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\OccupancyGrid;
use App\Support\CountrySettings;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * The next fortnight at a glance, under the arrivals.
 *
 * Deliberately **read only**, even though it renders the same grid the
 * Calendar page does: a dashboard is where somebody looks before deciding
 * what to do, and a booking form opening from a widget on the landing page is
 * a way to take a booking by accident. Clicking through to the page is one
 * step, and that page can write.
 */
class CalendarWidget extends Widget
{
    protected static string $view = 'filament.partner.widgets.calendar';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    /**
     * Rendered with the page, not fetched afterwards. Filament defers widgets
     * by default, which turns opening the dashboard into three round trips
     * instead of one — and these lodges are on satellite links, where a round
     * trip is the expensive part. Both widgets read a bounded number of rows,
     * so there is nothing here worth deferring.
     */
    protected static bool $isLazy = false;

    /** Two weeks: enough to see the shape of the week ahead without becoming the page. */
    private const DAYS = 14;

    public int $offset = 0;

    public static function canView(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    public function shift(int $days): void
    {
        $this->offset = max(-28, min(56, $this->offset + $days));
    }

    public function today(): void
    {
        $this->offset = 0;
    }

    public function grid(): ?OccupancyGridData
    {
        $property = $this->property();

        return $property === null
            ? null
            : app(OccupancyGrid::class)->build($property, $this->start(), self::DAYS);
    }

    public function calendarUrl(): string
    {
        return OccupancyCalendar::getUrl(['from' => $this->start()->toDateString()]);
    }

    private function start(): Carbon
    {
        $property = $this->property();
        $today = $property === null
            ? Carbon::now()->startOfDay()
            : CountrySettings::for($property)->today();

        return $today->addDays($this->offset);
    }

    private function property(): ?Listing
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
        ];
    }
}
