<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Pages\ArrivalsDepartures;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Services\Inventory\ArrivalsBoard;
use App\Services\Inventory\DTOs\ArrivalsBoardData;
use App\Support\CountrySettings;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Who arrives today, on the first screen after signing in.
 *
 * This is the question a lodge asks before any other one, so it should not
 * need a click to reach. The arrows step a day at a time because the other
 * two questions are "who came yesterday" and "who is coming tomorrow"; the
 * full board with departures and in-house guests stays one page away.
 */
class ArrivalsTodayWidget extends Widget
{
    protected static string $view = 'filament.partner.widgets.arrivals-today';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /**
     * Rendered with the page, not fetched afterwards. Filament defers widgets
     * by default, which turns opening the dashboard into three round trips
     * instead of one — and these lodges are on satellite links, where a round
     * trip is the expensive part. Both widgets read a bounded number of rows,
     * so there is nothing here worth deferring.
     */
    protected static bool $isLazy = false;

    /** Days away from the property's today. Not in the URL — a dashboard is a starting point. */
    public int $offset = 0;

    public static function canView(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    public function shift(int $days): void
    {
        // Bounded so the dashboard cannot be walked into next year by holding
        // an arrow down; that is what the full board is for.
        $this->offset = max(-14, min(14, $this->offset + $days));
    }

    public function today(): void
    {
        $this->offset = 0;
    }

    public function board(): ?ArrivalsBoardData
    {
        $property = $this->property();

        return $property === null ? null : app(ArrivalsBoard::class)->forDate($property, $this->date());
    }

    public function boardUrl(): string
    {
        return ArrivalsDepartures::getUrl(['date' => $this->date()->toDateString()]);
    }

    /** The property's own date, not the server's — see OccupancyCalendar. */
    private function date(): Carbon
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
            'board' => $this->board(),
            'property' => $this->property(),
            'isToday' => $this->offset === 0,
        ];
    }
}
