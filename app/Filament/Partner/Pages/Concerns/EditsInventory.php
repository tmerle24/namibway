<?php

namespace App\Filament\Partner\Pages\Concerns;

use App\Enums\BlockReason;
use App\Enums\GuestKind;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Exceptions\Inventory\InventoryUnavailableException;
use App\Exceptions\Inventory\StayRuleViolationException;
use App\Exceptions\Pricing\PromotionUnavailableException;
use App\Exceptions\Pricing\UnpriceableStayException;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\GuestCategory;
use App\Models\Listing;
use App\Models\Note;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Booking\BookingMailbox;
use App\Services\Booking\RoomCapacity;
use App\Services\Inventory\DTOs\BlockRequest;
use App\Services\Inventory\DTOs\ManualBookingLinePreview;
use App\Services\Inventory\DTOs\ManualBookingPreview;
use App\Services\Inventory\InventoryWriter;
use App\Services\Inventory\ManualBooking;
use App\Services\Pricing\ComputedCharge;
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
    public ?int $prefillBookableUnitId = null;

    public ?string $prefillDate = null;

    /** Set by a click on a departure block, so the form opens on that one. */
    public ?int $prefillSlotId = null;

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
    public function startBooking(int $bookableUnitId, string $date): void
    {
        $property = $this->property();

        if ($property === null) {
            return;
        }

        $room = $property->bookableUnits()->find($bookableUnitId);

        $this->prefillBookableUnitId = $room?->id;
        $this->prefillDate = $this->parseDate($date)?->toDateString();

        $this->mountAction('createBooking');
    }

    /**
     * Start a booking from a departure block on the day view.
     *
     * The same shape as startBooking() and resolved the same way, with one
     * more question asked: the departure has to belong to the room type it was
     * named with, or the form would open on the 09:00 quad tour with the game
     * drive selected.
     *
     * The dates come from here rather than from the operator: a seat is sold
     * on the day it departs, and making a desk type an arrival and a departure
     * date to sell a morning ride is how a screen stops being used.
     */
    public function startDeparture(int $bookableUnitId, string $date, int $slotId): void
    {
        $property = $this->property();

        if ($property === null) {
            return;
        }

        $room = $property->bookableUnits()->find($bookableUnitId);
        $slot = $room === null ? null : $room->slots()->where('is_active', true)->find($slotId);

        $this->prefillBookableUnitId = $room?->id;
        $this->prefillDate = $this->parseDate($date)?->toDateString();
        $this->prefillSlotId = $slot?->id;

        $this->mountAction('createBooking');
    }

    /**
     * Taking a booking is the thing this system is for, so the form is one
     * page and not a wizard.
     *
     * A wizard is right when a task is long, branching, and done once by
     * somebody who has never done it before. This is the opposite: a desk
     * takes the same booking thirty times a day, and every field depends on
     * the others — change the dates and the price changes, change the room and
     * the availability changes. Splitting that across steps hides the number
     * somebody is watching and turns four keystrokes into four clicks. Tabs
     * are worse still: a validation error can land on a tab nobody is looking
     * at. Every property management system worth using does this as one dense
     * form, and so does this one.
     *
     * What the form *does* need is what a long form always needs: the heading
     * and the save button stay put while the middle scrolls, so the total and
     * the way out are never below the fold.
     */
    public function createBookingAction(): Action
    {
        return Action::make('createBooking')
            ->label('New booking')
            ->icon('heroicon-m-plus')
            ->modalHeading('New booking')
            ->modalDescription('A walk-in, a telephone booking, or one taken somewhere else and recorded here.')
            ->modalSubmitActionLabel('Save booking')
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
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
        $plans = $this->sellableRatePlans();

        $room = [
            'bookable_unit_id' => $this->prefillBookableUnitId ?? array_key_first($this->bookableBookableUnits()),
            'rate_plan_id' => array_key_first($plans),
            'slot_id' => $this->prefillSlotId,
        ];

        // Two adults is the booking a desk takes all day. Prefilling it is the
        // difference between confirming a form and filling one in.
        $room += $this->pricesByGuests()
            ? ['guests' => [['guest_category_id' => $this->defaultGuestCategoryId(), 'count' => 2]]]
            : ['quantity' => 1];

        return [
            'check_in' => $arrival,
            'check_out' => Carbon::parse($arrival)->addDay()->toDateString(),
            'rooms' => [$room],
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
                        ->addActionLabel($this->pricesByGuests() ? 'Add another room' : 'Add another room type')
                        ->schema($this->roomLineSchema())
                        ->columns(2)
                        ->minItems(1)
                        ->defaultItems(1),

                    // Beside the rooms rather than in the folded price
                    // section: a code is something a guest reads out at the
                    // start of the conversation, and the total below has to
                    // react to it while they are still on the phone.
                    TextInput::make('promotion_code')
                        ->label('Offer code')
                        ->maxLength(40)
                        ->placeholder('Only if the guest has one')
                        ->live(onBlur: true),

                    Placeholder::make('availability')
                        ->label('Availability and price')
                        ->content(fn (Get $get): HtmlString => $this->bookingPreviewHtml($get)),
                ]),

            // Three across rather than two: the same six fields in two rows
            // instead of three, which is one screenful less to scroll through
            // on the laptop a reception desk actually has.
            Section::make('Guest')
                ->columns(3)
                ->schema([
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
                    // Live, because the capacity warning below is about these
                    // two numbers and a warning that only appears on save is a
                    // warning nobody reads.
                    TextInput::make('adults')->label('Adults')->numeric()->minValue(1)->maxValue(99)->default(2)->required()->live(onBlur: true),
                    TextInput::make('children')->label('Children')->numeric()->minValue(0)->maxValue(99)->default(0)->required()->live(onBlur: true),

                    /*
                     * More people than the rooms sleep. Not a refusal: at a
                     * desk a cot goes in the room, and a receptionist told
                     * "no" by software they cannot argue with writes the
                     * booking on paper — after which the property's own system
                     * does not know about the guest at all.
                     *
                     * But it is not silent either, which is what it used to
                     * be. The numbers are named once, in the price block above
                     * where every other thing about this booking is reported,
                     * and this is where the desk answers them.
                     */
                    TextInput::make('over_capacity_note')
                        ->label('More people than the room sleeps — what is being done?')
                        ->placeholder('Extra bed, cot, child shares …')
                        ->maxLength(200)
                        ->visible(fn (Get $get): bool => $this->overCapacity($get) !== null)
                        ->required(fn (Get $get): bool => $this->overCapacity($get) !== null)
                        ->helperText('Kept on the booking, so whoever makes the room up knows.')
                        ->columnSpanFull(),
                ]),

            // Folded away, because almost no booking needs it. A field that is
            // used once a month costs a screenful every other time it is open.
            Section::make('Price and notes')
                ->description('The calendar prices every night. Open this only to charge something else, or to note something about the stay.')
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
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
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(2)
                        ->maxLength(2000)
                        ->placeholder('Late arrival, dietary requirements, anything the desk should know.')
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * One room line.
     *
     * Which fields it has is decided by what the property actually sells, not
     * by what the system can express — see BOOKING_SYSTEM.md, "unused things
     * stay invisible". A guesthouse with one per-room tariff sees a room type
     * and a number of units, exactly as before. A lodge pricing per person
     * sees guest rows instead, and its lines hold one room each, because two
     * adults in one chalet and a family in the next are different prices.
     *
     * @return array<int, mixed>
     */
    private function roomLineSchema(): array
    {
        $plans = $this->sellableRatePlans();

        $fields = [
            Select::make('bookable_unit_id')
                ->label('Room type')
                ->options(fn (): array => $this->bookableBookableUnits())
                ->required()
                ->live(),
            // Only for a unit that runs departures, and required for one.
            // Leaving it blank there would take the seat off the property's
            // own counter instead of off the tour — a different pool, and the
            // calendar would go on offering a departure that is full.
            Select::make('slot_id')
                // Not "Departure": that word is already the check-out date at
                // the top of this same form, and two fields called the same
                // thing on one screen is how a desk books the wrong one.
                ->label('Which departure')
                ->options(fn (Get $get): array => $this->departureOptions($get('bookable_unit_id')))
                ->visible(fn (Get $get): bool => $this->departureOptions($get('bookable_unit_id')) !== [])
                ->required(fn (Get $get): bool => $this->departureOptions($get('bookable_unit_id')) !== [])
                ->live(),
        ];

        // A property with one product never chose it, so it is never asked.
        if (count($plans) > 1) {
            $fields[] = Select::make('rate_plan_id')
                ->label('Rate')
                ->options($plans)
                ->default(array_key_first($plans))
                ->required()
                ->live();
        }

        if (! $this->pricesByGuests()) {
            $fields[] = TextInput::make('quantity')
                ->label(fn (Get $get): string => $this->departureOptions($get('bookable_unit_id')) === [] ? 'Units' : 'Seats')
                ->numeric()
                ->minValue(1)
                ->maxValue(99)
                ->default(1)
                ->required()
                ->live(onBlur: true);

            return $fields;
        }

        $fields[] = Repeater::make('guests')
            ->label('Who is in the room')
            ->addActionLabel('Add a guest type')
            ->schema([
                Select::make('guest_category_id')
                    ->label('Guest')
                    ->options(fn (): array => $this->guestCategoryOptions())
                    ->required()
                    ->live(),
                TextInput::make('count')
                    ->label('How many')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true),
            ])
            ->columns(2)
            ->minItems(1)
            ->defaultItems(1)
            ->columnSpanFull();

        return $fields;
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

        $preview = $manual->preview($property, $checkIn, $checkOut, $rooms, $data['promotion_code'] ?? null);

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
                promotionCode: $data['promotion_code'] ?? null,
                overCapacityNote: $data['over_capacity_note'] ?? null,
            );
        } catch (InventoryUnavailableException|StayRuleViolationException $refusal) {
            // The preview said yes a moment ago and the writer says no, which
            // means somebody else booked it in between. That is the system
            // working, so it is reported as a fact rather than as an error.
            $this->refuse('That room went while the form was open', [$refusal->getMessage()]);
        } catch (PromotionUnavailableException $refusal) {
            // The code stopped working between the preview and the save, which
            // means somebody else took the last use of it.
            $this->refuse('That offer is no longer available', [$refusal->getMessage()]);
        } catch (UnpriceableStayException $unpriceable) {
            // A different sentence, and a different fix: the room is free and
            // nobody has said what it costs.
            $this->refuse('That stay has no price yet', [$unpriceable->getMessage()]);
        }

        // Where the confirmation went, said at the one moment it matters. The
        // panel used to carry this as a strip above every screen, which is
        // both too often and — while somebody is booking — too far from the
        // click that sent the mail. It is silent once a property is live.
        $holding = BookingMailbox::for($reservation)->holdingNotice();

        Notification::make()
            ->success()
            ->title('Booking saved')
            ->body($reservation->reference.' — '.$reservation->guest_name
                .($holding === null ? '' : '. '.$holding))
            ->send();

        $this->prefillBookableUnitId = null;
        $this->prefillDate = null;
        $this->prefillSlotId = null;
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
            is_string($get('promotion_code')) ? $get('promotion_code') : null,
            // A park permit is charged per person, so the preview has to know
            // how many people — otherwise the number read out to the guest is
            // not the number they are charged.
            max(1, (int) $get('adults')),
            max(0, (int) $get('children')),
        );

        if ($preview->problems !== []) {
            $items = collect($preview->problems)
                ->map(fn (string $problem) => '<li>'.e($problem).'</li>')
                ->implode('');

            return new HtmlString(
                '<ul style="color: rgb(185 28 28); list-style: disc; margin-left: 1.1rem;">'.$items.'</ul>'
            );
        }

        $categories = [];

        foreach (GuestCategory::forListing($property) as $category) {
            $categories[$category->id] = $category;
        }

        $lines = collect($preview->lines)
            ->map(function (ManualBookingLinePreview $line) use ($categories): string {
                $who = $line->occupancyLabel($categories);
                $departure = $line->departureLabel();

                return '<li>'.e(
                    $line->bookableUnit->name.' ×'.$line->quantity
                    .($departure === null ? '' : ' — '.$departure)
                    .($who === null ? '' : ' ('.$who.')')
                    .' — '.Money::format($line->total, $line->currency)
                    .' ('.$line->unitsFree.($departure === null ? ' free)' : ' seats free)')
                ).'</li>';
            })
            ->implode('');

        // The total is the number somebody reads out loud to the guest on the
        // phone, so it is the largest thing in the block rather than the last
        // line of a list.
        // An offer is shown as what it took off, not only as a smaller total:
        // a guest who read out a code wants to hear the discount, and a desk
        // explaining the bill needs both numbers.
        // Amber and not red: a problem stops the booking, a warning asks a
        // question this desk is allowed to answer.
        $warnings = $preview->hasWarnings()
            ? '<div style="margin-top: .35rem; color: rgb(180 83 9);">'
                .implode('', array_map(fn (string $line): string => '<div>'.e($line).'</div>', $preview->warnings))
                .'</div>'
            : '';

        $offer = $preview->discount > 0.0
            ? '<div style="margin-top: .35rem; color: rgb(21 128 61);">'
                .e($preview->offer ?? 'Offer')
                .' — '.e(Money::format($preview->discount, $preview->currency)).' off '
                .e(Money::format($preview->beforeDiscount(), $preview->currency))
                .'</div>'
            : '';

        return new HtmlString(
            '<div style="display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;">'
            .'<span style="font-size: 1.5rem; font-weight: 600;">'
            .e(Money::format($preview->total, $preview->currency))
            .'</span>'
            .'<span style="opacity: .7;">'
            .e($preview->lengthLabel())
            .'</span>'
            .'</div>'
            .$warnings
            .$offer
            .$this->chargeLinesHtml($preview)
            .'<ul style="list-style: disc; margin: .35rem 0 0 1.1rem;">'.$lines.'</ul>'
        );
    }

    /**
     * Taxes and fees, named under the total.
     *
     * Shown even when they change nothing — a rate that already contains VAT
     * is exactly the case a guest asks about, and "VAT 15% included" is the
     * sentence a desk needs to be able to read out.
     */
    private function chargeLinesHtml(ManualBookingPreview $preview): string
    {
        if ($preview->charges === []) {
            return '';
        }

        $lines = collect($preview->charges)
            ->map(fn (ComputedCharge $charge): string => '<div>'
                .e($charge->name)
                .($charge->basis->isPercentage()
                    ? ' '.e(rtrim(rtrim(number_format($charge->rate, 2), '0'), '.')).'%'
                    : '')
                .' — '.e(Money::format($charge->amount, $preview->currency))
                .($charge->isIncluded ? ' <span style="opacity: .7;">included</span>' : '')
                .'</div>')
            ->implode('');

        return '<div style="margin-top: .35rem; opacity: .85;">'
            .'<div style="opacity: .7;">'.e(Money::format($preview->stayAmount(), $preview->currency)).' for the stay</div>'
            .$lines
            .'</div>';
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

    /**
     * A note on the stay, with who wrote it and when.
     *
     * Separate from the `notes` column above it, which is what the booking
     * itself said and does not change. This is the running log — "guest called
     * to move the arrival forward", "paid the balance in cash" — and it is the
     * half a desk actually uses during a stay.
     */
    public function addStayNoteAction(): Action
    {
        return Action::make('addStayNote')
            ->label('Add a note')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading('Add a note to this stay')
            ->modalSubmitActionLabel('Save the note')
            ->form([
                Textarea::make('body')
                    ->label('Note')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000)
                    ->placeholder('Called to say they will arrive late. Asked to keep dinner.'),
            ])
            ->action(function (array $data): void {
                $reservation = $this->selectedReservation();

                if ($reservation === null) {
                    return;
                }

                /** @var User|null $author */
                $author = auth()->user();

                Note::write($reservation, (string) $data['body'], $author);

                Notification::make()->success()->title('Note added')->send();
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
                'bookable_unit_id' => $this->prefillBookableUnitId ?? array_key_first($this->bookableBookableUnits()),
                'units' => 1,
                'reason' => BlockReason::Maintenance->value,
            ])
            ->form(fn (): array => $this->blockForm())
            ->action(function (array $data): void {
                $room = $this->requireBookableUnit($data['bookable_unit_id'] ?? null);

                try {
                    app(InventoryWriter::class)->block(new BlockRequest(
                        bookableUnit: $room,
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
                    'bookable_unit_id' => $block->bookable_unit_id,
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

                $room = $this->requireBookableUnit($data['bookable_unit_id'] ?? null);

                try {
                    app(InventoryWriter::class)->updateBlock($block, new BlockRequest(
                        bookableUnit: $room,
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
                Select::make('bookable_unit_id')
                    ->label('Room type')
                    ->options(fn (): array => $this->bookableBookableUnits())
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
    private function bookableBookableUnits(): array
    {
        $property = $this->property();

        if ($property === null) {
            return [];
        }

        return $property->bookableUnits()
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (BookableUnit $room) => $room->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (BookableUnit $room) => [$room->id => $room->name.' ('.$room->code.')'])
            ->all();
    }

    /**
     * The sentence to show when the party does not fit what is being booked,
     * or null when it does.
     *
     * Reads the form as it stands rather than running the whole preview: this
     * runs on every keystroke that matters, and the question — do these people
     * fit these rooms — needs no prices, no calendar and no queries beyond the
     * room types themselves.
     */
    private function overCapacity(Get $get): ?string
    {
        // Under a plan that prices by guests, each room line carries its own
        // people and the pricer refuses a line it cannot price. Saying it
        // again here, from the header counts, would be a second voice on one
        // question — see ManualBooking::capacityWarnings().
        if ($this->pricesByGuests()) {
            return null;
        }

        $property = $this->property();

        if ($property === null) {
            return null;
        }

        $lines = [];

        foreach ($this->roomRows($get('rooms')) as $row) {
            // A seat on a departure is not a room and sleeps nobody.
            if (filled($row['slot_id'] ?? null)) {
                continue;
            }

            $bookableUnit = $property->bookableUnits()->find((int) ($row['bookable_unit_id'] ?? 0));
            $quantity = (int) ($row['quantity'] ?? 0);

            if ($bookableUnit instanceof BookableUnit && $quantity > 0) {
                $lines[] = [$bookableUnit, $quantity];
            }
        }

        $adults = (int) ($get('adults') ?? 0);
        $children = (int) ($get('children') ?? 0);

        if ($lines === [] || $adults < 1 || RoomCapacity::fitsAcross($lines, $adults, $children)) {
            return null;
        }

        return RoomCapacity::explain($lines, $adults, $children);
    }

    /**
     * The departures of one of this property's units, for the room line.
     *
     * Empty for everything sold by the night, which is what hides the field:
     * a lodge never learns that departures exist.
     *
     * @return array<int, string>
     */
    private function departureOptions(mixed $bookableUnitId): array
    {
        $property = $this->property();
        $id = (int) $bookableUnitId;

        if ($property === null || $id < 1) {
            return [];
        }

        $room = $property->bookableUnits()->find($id);

        if (! $room instanceof BookableUnit) {
            return [];
        }

        return BookingSlot::forUnit($room)
            ->mapWithKeys(fn (BookingSlot $slot) => [$slot->id => $slot->timeLabel().' — '.$slot->label()])
            ->all();
    }

    /**
     * Whether this property's booking form asks who is in the room.
     *
     * Both halves are needed: a plan that prices per person, and categories to
     * price them by. A property that has one without the other is mid-setup,
     * and the honest thing is to keep taking bookings the old way rather than
     * show a select with nothing in it — the rates screen is where the missing
     * half is offered.
     */
    private function pricesByGuests(): bool
    {
        $property = $this->property();

        if ($property === null) {
            return false;
        }

        if ($this->guestCategoryOptions() === []) {
            return false;
        }

        foreach (RatePlan::forListing($property) as $plan) {
            if ($plan->pricing_strategy->needsOccupancy()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The products this property sells, for the rate select on a room line.
     *
     * @return array<int, string>
     */
    private function sellableRatePlans(): array
    {
        $property = $this->property();

        if ($property === null) {
            return [];
        }

        return RatePlan::forListing($property)
            ->mapWithKeys(fn (RatePlan $plan) => [$plan->id => $plan->label()])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function guestCategoryOptions(): array
    {
        $property = $this->property();

        if ($property === null) {
            return [];
        }

        return GuestCategory::forListing($property)
            ->mapWithKeys(fn (GuestCategory $category) => [$category->id => $category->label()])
            ->all();
    }

    /** Adults, where the property has them — the first row of any rate sheet. */
    private function defaultGuestCategoryId(): ?int
    {
        $property = $this->property();

        if ($property === null) {
            return null;
        }

        $categories = GuestCategory::forListing($property);
        $adult = $categories->first(fn (GuestCategory $category) => $category->kind === GuestKind::Adult);

        return ($adult ?? $categories->first())?->id;
    }

    private function requireProperty(): Listing
    {
        $property = $this->property();

        if ($property === null) {
            $this->refuse('No property selected', ['Choose a property before entering a booking.']);
        }

        return $property;
    }

    private function requireBookableUnit(mixed $id): BookableUnit
    {
        $property = $this->requireProperty();
        $room = $property->bookableUnits()->find((int) $id);

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
            : $property->sellingCurrency();
    }

    /**
     * Repeater rows as they come back from the form: keyed by uuid, and
     * `mixed` as far as any type checker is concerned. Nested repeaters (the
     * guest rows) arrive the same way, and ManualBooking reads them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function roomRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach (array_values($value) as $row) {
            if (is_array($row)) {
                $rows[] = $this->normaliseRow($row);
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        if (is_array($row['guests'] ?? null)) {
            $row['guests'] = array_values($row['guests']);
        }

        return $row;
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
