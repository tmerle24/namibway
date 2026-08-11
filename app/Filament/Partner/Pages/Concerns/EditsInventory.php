<?php

namespace App\Filament\Partner\Pages\Concerns;

use App\Enums\BlockReason;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Models\Listing;
use App\Models\RoomType;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\ManualBookingLinePreview;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\ManualBooking;
use App\Support\CountrySettings;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Throwable;

/**
 * Everything a lodge *does* on the two screens slice 2 only let it read:
 * enter a walk-in, move a guest through the day, take rooms off sale.
 *
 * Every write here goes through InventoryWriter. Nothing in this trait
 * touches an inventory table, an inventory model or a counter — a Filament
 * action reaching into the tables was the exact scenario the single write
 * path and its architecture test were built to prevent, and the temptation
 * arrives with the first screen that can write.
 *
 * Refusals are stated in words a front desk can act on. "Sold out" ends a
 * conversation with a guest standing at the counter; "only one of two
 * Standard Chalets is free on 14 September" lets them offer something else.
 */
trait EditsInventory
{
    /**
     * Set by a click on an empty calendar cell, so the booking form opens
     * already knowing which room type and which night the person meant.
     */
    public ?int $prefillRoomTypeId = null;

    public ?string $prefillDate = null;

    /** The property the page is showing — see SelectedProperty. */
    abstract protected function property(): ?Listing;

    /*
    |--------------------------------------------------------------------------
    | Taking a booking
    |--------------------------------------------------------------------------
    */

    /**
     * Start a booking from an empty cell. The room type and the night come
     * from the browser, so both are resolved against this property before
     * anything is prefilled — an id from elsewhere prefills nothing rather
     * than quietly selecting another partner's room.
     */
    public function startBooking(int $roomTypeId, string $date): void
    {
        $property = $this->property();

        if ($property === null) {
            return;
        }

        $room = $property->roomTypes()->find($roomTypeId);

        $this->prefillRoomTypeId = $room?->id;
        $this->prefillDate = $this->parseDate($date)?->toDateString();

        $this->mountAction('createBooking');
    }

    public function createBookingAction(): Action
    {
        return Action::make('createBooking')
            ->label('New booking')
            ->icon('heroicon-m-plus')
            ->modalHeading('New booking')
            ->modalDescription('A walk-in, a telephone booking, or one taken somewhere else and recorded here.')
            ->modalSubmitActionLabel('Save booking')
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->fillForm(fn (): array => $this->bookingPrefill())
            ->form(fn (): array => $this->bookingForm())
            ->action(fn (array $data) => $this->placeBooking($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPrefill(): array
    {
        $arrival = $this->prefillDate ?? $this->propertyToday()->toDateString();

        return [
            'check_in' => $arrival,
            'check_out' => Carbon::parse($arrival)->addDay()->toDateString(),
            'rooms' => [[
                'room_type_id' => $this->prefillRoomTypeId ?? array_key_first($this->bookableRoomTypes()),
                'quantity' => 1,
            ]],
            'source' => ReservationSource::WalkIn->value,
            'adults' => 2,
            'children' => 0,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function bookingForm(): array
    {
        return [
            Section::make('Stay')
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('check_in')
                            ->label('Arrival')
                            ->native(false)
                            ->displayFormat('D, d M Y')
                            ->required()
                            ->live(),
                        DatePicker::make('check_out')
                            ->label('Departure')
                            ->native(false)
                            ->displayFormat('D, d M Y')
                            ->required()
                            ->after('check_in')
                            ->live(),
                    ]),

                    Repeater::make('rooms')
                        ->label('Rooms')
                        ->addActionLabel('Add another room type')
                        ->schema([
                            Select::make('room_type_id')
                                ->label('Room type')
                                ->options(fn (): array => $this->bookableRoomTypes())
                                ->required()
                                ->live(),
                            TextInput::make('quantity')
                                ->label('Units')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(99)
                                ->default(1)
                                ->required()
                                ->live(onBlur: true),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->defaultItems(1),

                    Placeholder::make('availability')
                        ->label('Availability and price')
                        ->content(fn (Get $get): HtmlString => $this->bookingPreviewHtml($get)),
                ]),

            Section::make('Guest')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('guest_name')->label('Name')->required()->maxLength(160),
                        Select::make('source')
                            ->label('Taken by')
                            ->options(collect(ReservationSource::cases())
                                // A stay entered here did not come through the
                                // website, so offering that would be a lie the
                                // form invited.
                                ->reject(fn (ReservationSource $source) => $source === ReservationSource::Website)
                                ->mapWithKeys(fn (ReservationSource $source) => [$source->value => $source->label()])
                                ->all())
                            ->default(ReservationSource::WalkIn->value)
                            ->required(),
                        TextInput::make('guest_email')->label('Email')->email()->maxLength(180),
                        TextInput::make('guest_phone')->label('Phone')->tel()->maxLength(40),
                        TextInput::make('adults')->label('Adults')->numeric()->minValue(1)->maxValue(99)->default(2)->required(),
                        TextInput::make('children')->label('Children')->numeric()->minValue(0)->maxValue(99)->default(0)->required(),
                    ]),
                ]),

            Section::make('Price')
                ->description('The calendar prices every night. Change the total only when the lodge is charging something else.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('total_override')
                            ->label('Total to charge')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn (): string => Money::symbol($this->propertyCurrency()))
                            ->helperText('Leave blank to charge what the calendar says.')
                            ->live(onBlur: true),
                        TextInput::make('override_reason')
                            ->label('Why')
                            ->maxLength(200)
                            ->placeholder('Operator rate, repeat guest, apology …')
                            ->required(fn (Get $get): bool => filled($get('total_override'))),
                    ]),
                ]),

            Textarea::make('notes')->label('Notes')->rows(2)->maxLength(2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function placeBooking(array $data): void
    {
        $property = $this->requireProperty();
        $manual = app(ManualBooking::class);

        $rooms = $this->roomRows($data['rooms'] ?? null);

        $checkIn = $this->parseDate($data['check_in'] ?? null);
        $checkOut = $this->parseDate($data['check_out'] ?? null);

        $preview = $manual->preview($property, $checkIn, $checkOut, $rooms);

        if ($checkIn === null || $checkOut === null || ! $preview->isBookable()) {
            $this->refuse(
                'The booking was not saved',
                $preview->problems === [] ? ['Check the dates and the rooms.'] : $preview->problems,
            );
        }

        try {
            $reservation = $manual->place(
                listing: $property,
                checkIn: $checkIn,
                checkOut: $checkOut,
                lines: $rooms,
                guestName: (string) $data['guest_name'],
                source: ReservationSource::from((string) $data['source']),
                guestEmail: $data['guest_email'] ?? null,
                guestPhone: $data['guest_phone'] ?? null,
                adults: (int) ($data['adults'] ?? 1),
                children: (int) ($data['children'] ?? 0),
                notes: $data['notes'] ?? null,
                createdBy: $this->currentUserId(),
                totalOverride: filled($data['total_override'] ?? null) ? (float) $data['total_override'] : null,
                overrideReason: $data['override_reason'] ?? null,
            );
        } catch (InventoryUnavailableException|StayRuleViolationException $refusal) {
            // The preview said yes a moment ago and the writer says no, which
            // means somebody else booked it in between. That is the system
            // working, so it is reported as a fact rather than as an error.
            $this->refuse('That room went while the form was open', [$refusal->getMessage()]);
        }

        Notification::make()
            ->success()
            ->title('Booking saved')
            ->body($reservation->reference.' — '.$reservation->guest_name)
            ->send();

        $this->prefillRoomTypeId = null;
        $this->prefillDate = null;
        $this->showReservation($reservation->id);
    }

    private function bookingPreviewHtml(Get $get): HtmlString
    {
        $property = $this->property();

        if ($property === null) {
            return new HtmlString('');
        }

        $preview = app(ManualBooking::class)->preview(
            $property,
            $this->parseDate($get('check_in')),
            $this->parseDate($get('check_out')),
            $this->roomRows($get('rooms')),
        );

        if ($preview->problems !== []) {
            $items = collect($preview->problems)
                ->map(fn (string $problem) => '<li>'.e($problem).'</li>')
                ->implode('');

            return new HtmlString(
                '<ul style="color: rgb(185 28 28); list-style: disc; margin-left: 1.1rem;">'.$items.'</ul>'
            );
        }

        $lines = collect($preview->lines)
            ->map(fn (ManualBookingLinePreview $line) => '<li>'.e(
                $line->roomType->name.' ×'.$line->quantity
                .' — '.Money::format($line->total, $line->currency)
                .' ('.$line->unitsFree.' free)'
            ).'</li>')
            ->implode('');

        return new HtmlString(
            '<ul style="list-style: disc; margin-left: 1.1rem;">'.$lines.'</ul>'
            .'<p style="margin-top: .4rem;"><strong>'
            .e($preview->nights.' '.str('night')->plural($preview->nights).' · '.Money::format($preview->total, $preview->currency))
            .'</strong></p>'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Moving a stay through its day
    |--------------------------------------------------------------------------
    */

    /**
     * The transitions that are legal from where this stay is now. The list
     * comes from the writer, so the buttons a screen offers and the rules the
     * domain enforces cannot drift apart.
     *
     * @return array<int, StayStatus>
     */
    public function availableTransitions(): array
    {
        $reservation = $this->selectedReservation();

        if ($reservation === null) {
            return [];
        }

        return InventoryWriter::allowedTransitions()[$reservation->status->value] ?? [];
    }

    public function transitionStay(string $to): void
    {
        $reservation = $this->selectedReservation();
        $status = StayStatus::tryFrom($to);

        if ($reservation === null || $status === null) {
            return;
        }

        try {
            app(InventoryWriter::class)->transition($reservation, $status);
        } catch (InvalidArgumentException $refusal) {
            Notification::make()->danger()->title('That is not possible')->body($refusal->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($reservation->guest_name.' — '.$status->label())->send();
    }

    public function cancelStayAction(): Action
    {
        return Action::make('cancelStay')
            ->label('Cancel stay')
            ->color('danger')
            ->icon('heroicon-m-x-circle')
            ->modalHeading('Cancel this stay')
            ->modalDescription('The rooms go back on sale immediately.')
            ->modalSubmitActionLabel('Cancel the stay')
            ->form([
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(2)
                    ->maxLength(200)
                    ->placeholder('Guest called to cancel, double booking, weather …'),
            ])
            ->action(function (array $data): void {
                $reservation = $this->selectedReservation();

                if ($reservation === null) {
                    return;
                }

                $cancelled = app(InventoryWriter::class)->cancel($reservation, $data['reason'] ?? null);

                Notification::make()
                    ->success()
                    ->title('Stay cancelled')
                    ->body($cancelled->status === StayStatus::CancelledLate
                        ? 'Recorded as a late cancellation — it falls inside the property’s penalty window.'
                        : 'The rooms are back on sale.')
                    ->send();
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Taking rooms off sale
    |--------------------------------------------------------------------------
    */

    public function createBlockAction(): Action
    {
        return Action::make('createBlock')
            ->label('Take rooms off sale')
            ->icon('heroicon-m-wrench-screwdriver')
            ->color('gray')
            ->modalHeading('Take rooms off sale')
            ->modalDescription('Maintenance, owner use, or rooms held for a group whose booking is not firm yet.')
            ->modalSubmitActionLabel('Take off sale')
            ->fillForm(fn (): array => [
                'first_night' => $this->prefillDate ?? $this->propertyToday()->toDateString(),
                'last_night' => $this->prefillDate ?? $this->propertyToday()->toDateString(),
                'room_type_id' => $this->prefillRoomTypeId ?? array_key_first($this->bookableRoomTypes()),
                'units' => 1,
                'reason' => BlockReason::Maintenance->value,
            ])
            ->form(fn (): array => $this->blockForm())
            ->action(function (array $data): void {
                $room = $this->requireRoomType($data['room_type_id'] ?? null);

                try {
                    app(InventoryWriter::class)->block(new BlockRequest(
                        roomType: $room,
                        units: (int) $data['units'],
                        firstNight: $this->requireDate($data['first_night'] ?? null, 'first night'),
                        lastNight: $this->requireDate($data['last_night'] ?? null, 'last night'),
                        reason: BlockReason::from((string) $data['reason']),
                        note: $data['note'] ?? null,
                        createdBy: $this->currentUserId(),
                    ));
                } catch (InventoryUnavailableException $refusal) {
                    $this->refuse('Those rooms could not be taken off sale', [$refusal->getMessage()]);
                }

                Notification::make()->success()->title('Rooms taken off sale')->send();
            });
    }

    public function editBlockAction(): Action
    {
        return Action::make('editBlock')
            ->label('Edit')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading('Edit what is off sale')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (): array {
                $block = $this->selectedBlock();

                return $block === null ? [] : [
                    'room_type_id' => $block->room_type_id,
                    'units' => $block->units,
                    'first_night' => $block->first_night->toDateString(),
                    'last_night' => $block->last_night->toDateString(),
                    'reason' => $block->reason->value,
                    'note' => $block->note,
                ];
            })
            ->form(fn (): array => $this->blockForm())
            ->action(function (array $data): void {
                $block = $this->selectedBlock();

                if ($block === null) {
                    return;
                }

                $room = $this->requireRoomType($data['room_type_id'] ?? null);

                try {
                    app(InventoryWriter::class)->updateBlock($block, new BlockRequest(
                        roomType: $room,
                        units: (int) $data['units'],
                        firstNight: $this->requireDate($data['first_night'] ?? null, 'first night'),
                        lastNight: $this->requireDate($data['last_night'] ?? null, 'last night'),
                        reason: BlockReason::from((string) $data['reason']),
                        note: $data['note'] ?? null,
                        createdBy: $block->created_by,
                    ));
                } catch (InventoryUnavailableException $refusal) {
                    $this->refuse('That change would not fit', [$refusal->getMessage()]);
                }

                Notification::make()->success()->title('Saved')->send();
            });
    }

    public function releaseBlockAction(): Action
    {
        return Action::make('releaseBlock')
            ->label('Put back on sale')
            ->color('danger')
            ->icon('heroicon-m-arrow-uturn-left')
            ->requiresConfirmation()
            ->modalHeading('Put these rooms back on sale')
            ->modalDescription('They become bookable again from now on.')
            ->action(function (): void {
                $block = $this->selectedBlock();

                if ($block === null) {
                    return;
                }

                app(InventoryWriter::class)->releaseBlock($block);
                $this->closeReservation();

                Notification::make()->success()->title('Back on sale')->send();
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function blockForm(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('room_type_id')
                    ->label('Room type')
                    ->options(fn (): array => $this->bookableRoomTypes())
                    ->required(),
                TextInput::make('units')->label('Units')->numeric()->minValue(1)->maxValue(99)->default(1)->required(),
                DatePicker::make('first_night')->label('First night')->native(false)->displayFormat('D, d M Y')->required(),
                DatePicker::make('last_night')
                    ->label('Last night')
                    ->native(false)
                    ->displayFormat('D, d M Y')
                    ->required()
                    // Both ends are nights and both are inclusive, so a single
                    // night off sale has the same date twice. afterOrEqual, not
                    // after.
                    ->afterOrEqual('first_night'),
                Select::make('reason')
                    ->label('Reason')
                    ->options(collect(BlockReason::cases())
                        ->mapWithKeys(fn (BlockReason $reason) => [$reason->value => $reason->label()])
                        ->all())
                    ->required(),
            ]),
            Textarea::make('note')->label('Note')->rows(2)->maxLength(500),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared plumbing
    |--------------------------------------------------------------------------
    */

    /**
     * Active room types of the selected property, for every select on this
     * page. Scoped to the property rather than filtered in the view, so a
     * room type id posted from a browser can only ever be one of these.
     *
     * Returned as a plain array rather than a Collection: Filament wants an
     * array anyway, and Collection's value type is invariant, so a collection
     * of labels does not satisfy Collection<int, string>.
     *
     * @return array<int, string>
     */
    private function bookableRoomTypes(): array
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

    private function requireProperty(): Listing
    {
        $property = $this->property();

        if ($property === null) {
            $this->refuse('No property selected', ['Choose a property before entering a booking.']);
        }

        return $property;
    }

    private function requireRoomType(mixed $id): RoomType
    {
        $property = $this->requireProperty();
        $room = $property->roomTypes()->find((int) $id);

        if ($room === null) {
            $this->refuse('Unknown room type', ['That room type does not belong to this property.']);
        }

        return $room;
    }

    /**
     * Report a refusal and stop, leaving the modal open with the reason on
     * screen rather than closing it over a half-done action.
     *
     * @param  array<int, string>  $reasons
     *
     * @throws Halt
     */
    private function refuse(string $title, array $reasons): never
    {
        Notification::make()
            ->danger()
            ->title($title)
            ->body(implode(' ', $reasons))
            ->persistent()
            ->send();

        throw new Halt;
    }

    /** auth()->id() widens to int|string|null; the inventory domain wants int|null. */
    private function currentUserId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }

    private function propertyToday(): Carbon
    {
        $property = $this->property();

        return $property === null ? Carbon::now()->startOfDay() : CountrySettings::for($property)->today();
    }

    private function propertyCurrency(): string
    {
        $property = $this->property();

        return $property === null
            ? CountrySettings::forCountry(null)->currency()
            : CountrySettings::for($property)->currency();
    }

    /**
     * Repeater rows as they come back from the form: keyed by uuid, and
     * `mixed` as far as any type checker is concerned.
     *
     * @return array<int, array{room_type_id?: int|string|null, quantity?: int|string|null}>
     */
    private function roomRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<int, array{room_type_id?: int|string|null, quantity?: int|string|null}> $rows */
        $rows = array_values($value);

        return $rows;
    }

    /**
     * A date the operation cannot proceed without. The form already requires
     * it; this is the belt for the case where it arrives unparseable anyway.
     */
    private function requireDate(mixed $value, string $what): Carbon
    {
        $date = $this->parseDate($value);

        if ($date === null) {
            $this->refuse('A date is missing', ["Choose a {$what}."]);
        }

        return $date;
    }

    /** Dates arrive from a form and from the query string, so parsing fails softly. */
    private function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
