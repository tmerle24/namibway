<?php

namespace App\Filament\Partner\Pages;

use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Inventory\AvailabilityCalendar;
use App\Services\Inventory\InventoryWriter;
use App\Support\CountrySettings;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Rates and restrictions for a range of nights, written in one go.
 *
 * Cell-by-cell rate entry is the fastest way to make a lodge manager close the
 * laptop: a season is half a year, and nobody is typing three hundred numbers.
 * So the unit of work here is a range — optionally only certain weekdays,
 * because "weekends cost more" is how rates are actually kept, and every
 * channel manager's bulk update carries that.
 *
 * **An empty field means "leave this alone".** That rule is load-bearing and
 * it is the same one the Excel import already uses: somebody setting a
 * weekend surcharge must not silently clear the minimum stay they set last
 * week. Clearing is available, but it has to be chosen.
 *
 * Everything is written through InventoryWriter, which refuses to touch the
 * counters — a rate edit can never reset what a booking has consumed. Rates go
 * to the property's rate plan and capacity to its inventory calendar, because
 * a room is sold once however many products it is offered under.
 */
class RatesAndAvailability extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Rates';

    protected static ?string $title = 'Rates and availability';

    protected static ?string $slug = 'rates';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.partner.pages.rates-and-availability';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    public function mount(): void
    {
        $today = $this->today();

        $this->getForm('form')?->fill([
            'room_type_ids' => array_keys($this->roomTypes()),
            'from' => $today->toDateString(),
            'to' => $today->copy()->addDays(30)->toDateString(),
            'weekdays' => [],
            'min_stay_mode' => '',
            'closed_to_arrival' => '',
            'closed_to_departure' => '',
            'units_mode' => '',
        ]);
    }

    public function getSubheading(): ?string
    {
        return $this->property()?->name;
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Section::make('Which nights')
                    ->schema([
                        CheckboxList::make('room_type_ids')
                            ->label('Room types')
                            ->options(fn (): array => $this->roomTypes())
                            ->columns(2)
                            ->bulkToggleable()
                            ->required(),

                        Grid::make(2)->schema([
                            DatePicker::make('from')
                                ->label('First night')
                                ->native(false)
                                ->displayFormat('D, d M Y')
                                ->required()
                                ->live(),
                            DatePicker::make('to')
                                ->label('Last night')
                                ->native(false)
                                ->displayFormat('D, d M Y')
                                ->required()
                                // Both ends are nights and both count, so one
                                // night is the same date twice.
                                ->afterOrEqual('from')
                                ->live(),
                        ]),

                        CheckboxList::make('weekdays')
                            ->label('Only these days')
                            ->helperText('Leave all unticked to write every night in the range.')
                            ->options([
                                1 => 'Monday',
                                2 => 'Tuesday',
                                3 => 'Wednesday',
                                4 => 'Thursday',
                                5 => 'Friday',
                                6 => 'Saturday',
                                7 => 'Sunday',
                            ])
                            ->columns(4)
                            ->bulkToggleable(),
                    ]),

                Section::make('What to change')
                    ->description('Anything left empty is left exactly as it is.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('rate')
                                ->label('Rate per night')
                                ->numeric()
                                ->minValue(0)
                                ->prefix(fn (): string => Money::symbol($this->currency()))
                                ->helperText('Empty leaves the rate untouched.'),

                            Select::make('min_stay_mode')
                                ->label('Minimum stay')
                                ->options(fn (): array => $this->minimumStayOptions())
                                ->default(''),

                            Select::make('closed_to_arrival')
                                ->label('Arrivals')
                                ->options([
                                    '' => 'Leave unchanged',
                                    '0' => 'Allowed',
                                    '1' => 'Closed to arrival',
                                ])
                                ->default(''),

                            Select::make('closed_to_departure')
                                ->label('Departures')
                                ->options([
                                    '' => 'Leave unchanged',
                                    '0' => 'Allowed',
                                    '1' => 'Closed to departure',
                                ])
                                ->default(''),

                            Select::make('units_mode')
                                ->label('Units on sale')
                                ->options([
                                    '' => 'Leave unchanged',
                                    'set' => 'Set a number for these nights',
                                    'default' => 'Back to the room type’s own total',
                                ])
                                ->default('')
                                ->live(),

                            TextInput::make('units_total')
                                ->label('Units on sale')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(999)
                                ->required(fn (Get $get): bool => $get('units_mode') === 'set')
                                ->visible(fn (Get $get): bool => $get('units_mode') === 'set'),
                        ]),
                    ]),
            ]);
    }

    /**
     * Write it. One call to the writer per room type, which is also the unit
     * a failure is reported in — a lodge needs to know which room type did not
     * take the change, not that "something went wrong".
     */
    public function apply(): void
    {
        $property = $this->property();

        if ($property === null) {
            return;
        }

        $data = $this->getForm('form')?->getState() ?? [];
        $attributes = $this->attributesFrom($data);

        if ($data === []) {
            return;
        }

        if ($attributes === []) {
            Notification::make()
                ->warning()
                ->title('Nothing to change')
                ->body('Fill in at least one of the values below the range.')
                ->send();

            return;
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();

        if ($to->lt($from)) {
            Notification::make()->danger()->title('The last night is before the first')->send();

            return;
        }

        $weekdays = $this->intList($data['weekdays'] ?? null);

        $rooms = $property->roomTypes()
            ->whereIn('id', $this->intList($data['room_type_ids'] ?? null))
            ->get();

        if ($rooms->isEmpty()) {
            Notification::make()->warning()->title('Choose at least one room type')->send();

            return;
        }

        if (array_key_exists('units_total', $attributes) && is_int($attributes['units_total'])) {
            $conflict = $this->capacityConflict($rooms, $attributes['units_total'], $from, $to);

            if ($conflict !== null) {
                Notification::make()->danger()->title('That capacity is already taken')->body($conflict)->send();

                return;
            }
        }

        $writer = app(InventoryWriter::class);
        $ratePlan = RatePlan::ensureDefaultFor($property);
        $days = $weekdays === [] ? null : $weekdays;

        $inventory = array_intersect_key($attributes, ['units_total' => true]);
        $rates = array_diff_key($attributes, $inventory);
        $nights = 0;

        try {
            foreach ($rooms as $room) {
                $written = 0;

                if ($rates !== []) {
                    $written = $writer->setRates($ratePlan, $room, $from, $to, $rates, $days);
                }

                if ($inventory !== []) {
                    $written = max($written, $writer->setInventory($room, $from, $to, $inventory, $days));
                }

                $nights += $written;
            }
        } catch (Throwable $failure) {
            Notification::make()->danger()->title('Nothing was changed')->body($failure->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Calendar updated')
            ->body($nights.' '.str('night')->plural($nights).' across '
                .$rooms->count().' '.str('room type')->plural($rooms->count()).'.')
            ->send();
    }

    /**
     * Checkbox-list state arrives as `mixed`, and its values as strings.
     *
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('intval', $value)) : [];
    }

    /**
     * Built rather than spread into a literal: an array literal renumbers
     * integer keys coming out of a spread, which would quietly turn "3 nights"
     * into an option worth 1.
     *
     * @return array<int|string, string>
     */
    private function minimumStayOptions(): array
    {
        $options = ['' => 'Leave unchanged', 'none' => 'No minimum'];

        foreach (range(2, 14) as $nights) {
            $options[$nights] = $nights.' nights';
        }

        return $options;
    }

    /**
     * Only the fields somebody actually filled in. An empty field means "leave
     * this alone", and turning that into an explicit null would clear
     * something nobody asked to clear.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        $attributes = [];

        if (filled($data['rate'] ?? null)) {
            $attributes['rate'] = round((float) $data['rate'], 2);
        }

        $minStay = (string) ($data['min_stay_mode'] ?? '');

        if ($minStay !== '') {
            $attributes['min_stay'] = $minStay === 'none' ? null : (int) $minStay;
        }

        foreach (['closed_to_arrival', 'closed_to_departure'] as $restriction) {
            $value = (string) ($data[$restriction] ?? '');

            if ($value !== '') {
                $attributes[$restriction] = $value === '1';
            }
        }

        $units = (string) ($data['units_mode'] ?? '');

        if ($units === 'default') {
            $attributes['units_total'] = null;
        } elseif ($units === 'set' && filled($data['units_total'] ?? null)) {
            $attributes['units_total'] = (int) $data['units_total'];
        }

        return $attributes;
    }

    /**
     * Whether lowering capacity would put a night below what is already sold
     * or blocked on it. The database refuses this as well, but a constraint
     * violation is not an answer — this one names the room type and the night.
     *
     * @param  Collection<int, RoomType>  $rooms
     */
    private function capacityConflict(Collection $rooms, int $units, Carbon $from, Carbon $to): ?string
    {
        $calendar = app(AvailabilityCalendar::class);

        foreach ($rooms as $room) {
            $busiest = $calendar->busiestNight($room, $from, $to);

            if ($busiest['units'] > $units) {
                $when = $busiest['date']?->isoFormat('D MMMM YYYY') ?? 'one of those nights';

                return "{$room->name} already has {$busiest['units']} units sold or off sale on {$when}, "
                    ."so it cannot go down to {$units}.";
            }
        }

        return null;
    }

    /**
     * Active room types of the selected property. Scoped here rather than in
     * the view, so an id posted from a browser can only ever be one of these.
     *
     * A plain array rather than a Collection: Filament wants one anyway, and
     * Collection's value type is invariant, so a collection of labels does not
     * satisfy Collection<int, string>.
     *
     * @return array<int, string>
     */
    private function roomTypes(): array
    {
        $property = $this->property();

        if ($property === null) {
            return [];
        }

        return $property->roomTypes()
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (RoomType $room) => $room->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (RoomType $room) => [$room->id => $room->name.' ('.$room->code.')'])
            ->all();
    }

    private function today(): Carbon
    {
        $property = $this->property();

        return $property === null ? Carbon::now()->startOfDay() : CountrySettings::for($property)->today();
    }

    private function currency(): string
    {
        $property = $this->property();

        return $property === null
            ? CountrySettings::forCountry(null)->currency()
            : CountrySettings::for($property)->currency();
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
            'property' => $this->property(),
            'hasRoomTypes' => $this->roomTypes() !== [],
        ];
    }
}
