<?php

namespace App\Filament\Partner\Pages;

use App\Enums\CalendarRange;
use App\Filament\Partner\Pages\Concerns\EditsInventory;
use App\Filament\Partner\Pages\Concerns\IssuesInvoices;
use App\Filament\Partner\Pages\Concerns\RecordsPayments;
use App\Filament\Partner\Pages\Concerns\ShowsReservationDetail;
use App\Filament\Partner\Support\CalendarPreferences;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Services\Inventory\DayGrid;
use App\Services\Inventory\DTOs\DayGridData;
use App\Services\Inventory\DTOs\OccupancyGridData;
use App\Services\Inventory\OccupancyGrid;
use App\Support\CountrySettings;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Room types down, nights across, stays and blocks as bars — the shape a lodge
 * already has on the wall.
 *
 * The grid itself is still a pure read: OccupancyGrid returns plain objects,
 * already clipped and lane-packed, so the view does no date arithmetic and
 * issues no queries no matter how many room types the property has.
 *
 * What the screen can now *do* lives in EditsInventory, and every one of those
 * writes goes through InventoryWriter. Nothing on this page touches an
 * inventory table.
 *
 * ## Two readings of one book
 *
 * The range is a day, a week or a month (CalendarRange), and in the day view a
 * property that runs departures also gets the other reading of the same rows —
 * the hour axis down the page, its departures across it (DayGrid). A property
 * that sells only nights never sees it: DayGrid returns null where there is no
 * timetable, so the screen is exactly the one it had before.
 *
 * Which reading is showing is remembered between visits (CalendarPreferences),
 * so an operator who works the week view opens on the week view. The date is
 * not: the calendar always lands on today.
 */
class OccupancyCalendar extends Page implements HasForms
{
    use EditsInventory;
    use InteractsWithForms;
    use IssuesInvoices;
    use RecordsPayments;
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

    /**
     * Which product's rates the cells show. In the URL for the same reason the
     * dates are: a rate sheet somebody is checking is a page they will want to
     * come back to.
     */
    #[Url(as: 'rate')]
    public ?int $ratePlanId = null;

    /**
     * How much is on screen. In the URL with the rest, because "September on
     * the month view" is the whole address of what somebody is looking at.
     *
     * Null here does not mean "month" — it means nothing was asked for, and
     * mount() then fills in what this operator last chose.
     */
    #[Url(as: 'range')]
    public ?string $range = null;

    /**
     * Minutes per row on the hour axis, for an operator who wants the day
     * drawn finer or coarser than its timetable implies. Null is the normal
     * state and means "work it out" — see DayGrid::resolution().
     */
    #[Url(as: 'res')]
    public ?int $resolution = null;

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    /**
     * Open the calendar the way this operator left it.
     *
     * Only what the query string did not already say: a link is an address and
     * still wins, and #[Url] has filled these in by the time this runs. What is
     * restored is the *reading* — range, resolution, rate plan — and never the
     * date, which stays today. See CalendarPreferences.
     */
    public function mount(): void
    {
        $preferences = app(CalendarPreferences::class);

        $this->range ??= $preferences->range()?->value;
        $this->resolution ??= $preferences->resolution();

        $property = $this->property();
        $storedRatePlan = $property === null ? null : $preferences->ratePlanId($property);

        if ($this->ratePlanId === null && $storedRatePlan !== null) {
            // Resolved rather than taken: a plan deleted since it was last
            // looked at falls back to the default, like any other stale id.
            $this->ratePlanId = $this->ratePlan($storedRatePlan)?->id;
        }
    }

    /**
     * The panel's default content width is meant for forms and tables, where a
     * measure that stops around 80 characters is the point. A wall planner is
     * the opposite: every centimetre is another night on screen, and the width
     * a lodge has is the width it should get.
     */
    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
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

    /** One whole range at a time — see CalendarRange::shift(). */
    public function shift(int $direction): void
    {
        $this->from = $this->range()->shift($this->start(), $direction)->toDateString();
        $this->closeReservation();
    }

    /**
     * Change how much is on screen, staying on what is being looked at.
     *
     * The anchor is today when today is on screen and the first day on screen
     * otherwise. Both halves matter: narrowing the current month to a week has
     * to give *this* week and not the one the 1st fell in, and narrowing March
     * has to stay in March rather than dropping the operator back home.
     */
    public function showRange(string $range): void
    {
        $anchor = $this->anchor();
        $this->range = CalendarRange::parse($range)->value;
        $this->from = $this->range()->start($anchor)->toDateString();
        $this->closeReservation();

        app(CalendarPreferences::class)->rememberRange($this->range());
    }

    /**
     * Jump to a month. Both selects post both values, so picking a month keeps
     * the year and picking a year keeps the month.
     *
     * Out-of-range input is clamped rather than refused — it arrives from a
     * form on a page anyone can edit, and a calendar is not the place to argue
     * about it.
     */
    public function jumpTo(mixed $month, mixed $year): void
    {
        $current = $this->start();
        $month = max(1, min(12, (int) $month));
        $year = max($current->year - 20, min($current->year + 20, (int) $year));

        $this->from = $this->range()->start(Carbon::create($year, $month, 1)->startOfDay())->toDateString();
        $this->closeReservation();
    }

    /** Draw the day finer or coarser. Only the hour axis reads this. */
    public function showResolution(int $minutes): void
    {
        $this->resolution = in_array($minutes, DayGrid::RESOLUTIONS, true) ? $minutes : null;

        app(CalendarPreferences::class)->rememberResolution($this->resolution);
    }

    /** Show another product's rates. Nothing else about the grid changes. */
    public function showRatePlan(?int $ratePlanId): void
    {
        $this->ratePlanId = $this->ratePlan($ratePlanId)?->id;
        $this->closeReservation();

        $property = $this->property();

        if ($property !== null) {
            app(CalendarPreferences::class)->rememberRatePlan($property, $this->ratePlanId);
        }
    }

    public function range(): CalendarRange
    {
        return CalendarRange::parse($this->range);
    }

    public function grid(): ?OccupancyGridData
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        $start = $this->start();

        return app(OccupancyGrid::class)->build(
            $property,
            $start,
            $this->range()->days($start),
            $this->ratePlan($this->ratePlanId),
            // In the day view a unit that runs departures is read on the hour
            // axis below, in full. Leaving it in the night grid as well would
            // print the same day twice, once summed and once in detail.
            omitDepartureUnits: $this->range()->isDay(),
        );
    }

    /**
     * The day read the other way round. Null for every property that sells by
     * the night, and outside the day view — which is where an hour axis has
     * nothing to be an axis of.
     */
    public function dayGrid(): ?DayGridData
    {
        $property = $this->property();

        if ($property === null || ! $this->range()->isDay()) {
            return null;
        }

        return app(DayGrid::class)->build(
            $property,
            $this->start(),
            $this->ratePlan($this->ratePlanId),
            $this->resolution,
        );
    }

    /**
     * The plans this property sells, for the switcher.
     *
     * A property with one plan gets an empty list rather than a switcher of
     * one: a control with a single option is a control that asks a question
     * nobody has.
     *
     * @return array<int, RatePlan>
     */
    public function ratePlans(): array
    {
        $property = $this->property();

        if ($property === null) {
            return [];
        }

        $plans = RatePlan::forListing($property);

        return $plans->count() > 1 ? $plans->all() : [];
    }

    public function shownRatePlan(): ?RatePlan
    {
        return $this->ratePlan($this->ratePlanId);
    }

    /**
     * An id from the query string resolved against this property, falling back
     * to the default plan. A rate plan id belonging to another lodge selects
     * nothing rather than showing its prices.
     */
    private function ratePlan(?int $ratePlanId): ?RatePlan
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        $chosen = $ratePlanId === null
            ? null
            : RatePlan::forListing($property)->firstWhere('id', $ratePlanId);

        return $chosen instanceof RatePlan ? $chosen : RatePlan::defaultFor($property);
    }

    /**
     * The first night on screen, snapped to the range: the 1st of the month,
     * the Monday of the week, or the day itself.
     *
     * A property's own date, not the server's — a lodge in Windhoek turning
     * over at midnight is not doing it in UTC.
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
            return $this->range()->start($today);
        }

        try {
            return $this->range()->start(Carbon::parse($this->from)->startOfDay());
        } catch (InvalidFormatException) {
            return $this->range()->start($today);
        }
    }

    /**
     * The day a range change should keep hold of: today where today is on
     * screen, the first day on screen otherwise.
     */
    private function anchor(): Carbon
    {
        $start = $this->start();
        $today = $this->propertyToday();
        $end = $start->copy()->addDays($this->range()->days($start));

        return $today->gte($start) && $today->lt($end) ? $today : $start;
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
        $start = $this->start();

        return [
            'grid' => $this->grid(),
            'dayGrid' => $this->dayGrid(),
            'property' => $this->property(),
            // Not `range`: a Livewire component's public properties reach the
            // view too, and `$range` there is already the raw string from the
            // query string.
            'ranges' => CalendarRange::cases(),
            'activeRange' => $this->range(),
            'rangeLabel' => $this->range()->describe($start),
            'jumpMonth' => $start->month,
            'jumpYear' => $start->year,
            'months' => $this->months(),
            'years' => $this->years($start),
            'resolutions' => DayGrid::RESOLUTIONS,
            'ratePlans' => $this->ratePlans(),
            'shownRatePlan' => $this->shownRatePlan(),
        ];
    }

    /**
     * Month names for the jump, in the panel's locale.
     *
     * @return array<int, string>
     */
    private function months(): array
    {
        $months = [];
        $january = Carbon::create(2000, 1, 1)->startOfDay();

        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = $january->copy()->addMonthsNoOverflow($month - 1)->isoFormat('MMMM');
        }

        return $months;
    }

    /**
     * The years the jump offers: last year through the two after this one.
     * Rates are entered a season ahead and looked back at for comparison, and
     * a select that scrolls is a select nobody uses.
     *
     * @return array<int, int>
     */
    private function years(Carbon $start): array
    {
        $today = Carbon::now()->year;

        return range(min($today, $start->year) - 1, max($today, $start->year) + 2);
    }
}
