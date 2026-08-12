<?php

namespace App\Filament\Pages;

use App\Models\PaymentSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

/**
 * The platform's own commission and deposit rates, editable without a deploy.
 *
 * These are commercial terms rather than configuration — they change when a
 * conversation with a partner changes — so they live in a settings row, the
 * same pattern MessagingSettings established. `config/payments.php` stays as
 * the value the row is seeded from.
 *
 * Everything here is the *default*. A partner or a single listing may override
 * either rate; this is what applies to everyone who has not been given a
 * different number.
 */
class CommissionSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Commission and deposits';

    protected static ?string $title = 'Commission and deposits';

    protected static string $view = 'filament.pages.commission-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $settings = PaymentSettings::current();

        $this->getForm('form')?->fill([
            'commission_rate' => $settings->commission_rate,
            'deposit_rate' => $settings->deposit_rate,
            'minimum_deposit_rate' => $settings->minimum_deposit_rate,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('What we earn')
                    ->description('Ours to set, at every level. A partner can see what they pay and cannot change it.')
                    ->schema([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Commission')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required()
                            ->suffix('%')
                            ->helperText('Taken on the stay before VAT and the tourism levy — never on the government’s share.'),
                    ]),

                Forms\Components\Section::make('What the guest pays up front')
                    ->description('The partner chooses their own deposit within the range below.')
                    ->schema([
                        Forms\Components\TextInput::make('deposit_rate')
                            ->label('Default deposit')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required()
                            ->suffix('%')
                            ->helperText('What a property collects up front unless it sets its own.'),

                        Forms\Components\TextInput::make('minimum_deposit_rate')
                            ->label('Lowest deposit a partner may set')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            // Left empty on purpose in the ordinary case: at
                            // exactly the commission rate nothing has to move
                            // between us and the partner at all, which is the
                            // cheapest arrangement that exists for both sides.
                            ->placeholder('Same as the commission')
                            ->helperText('Leave empty to follow the commission. At that floor nothing has to be paid out or invoiced in either direction — no payout run, no statement, no reconciliation.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        PaymentSettings::current()->update($this->getForm('form')?->getState() ?? []);

        Notification::make()->title('Settings saved')->success()->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }
}
