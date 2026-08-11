<?php

namespace App\Filament\Pages;

use App\Models\MessageSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;

/**
 * Lets an admin edit the sign-off appended to outgoing "Contact owner"
 * messages (see PartnerContactMail) without a code change/deploy.
 */
class MessagingSettings extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Message Settings';

    protected static ?string $title = 'Messaging settings';

    protected static string $view = 'filament.pages.messaging-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->getForm('form')?->fill([
            'signature' => MessageSettings::current()->signature,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('signature')
                    ->label('Signature')
                    ->rows(4)
                    ->placeholder("Thanks,\nNamibWay")
                    ->helperText('Appended to the end of every outgoing "Contact owner" message.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        MessageSettings::current()->update($this->getForm('form')?->getState() ?? []);

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
