<?php

namespace App\Livewire;

use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Services\Enrichment\ClaimInviteService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * The Listing edit page's "Messages" tab. A plain Livewire component (rather
 * than a Filament RelationManager) so it can sit as a peer of Basic
 * information/Media/etc. in the same top-level Tabs component instead of
 * Filament's relation-manager tabs, which only combine at the whole-form
 * level (see ListingResource::form()).
 */
class ListingMessagesPanel extends Component implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    public Listing $record;

    /**
     * IDs that were unread when the tab was mounted — captured before marking
     * them read below, so the "new since last visit" bolding in table() still
     * has something to key off during this same page view.
     *
     * @var array<int, int>
     */
    public array $justReadMessageIds = [];

    public function mount(): void
    {
        $partner = $this->getPartner();

        if ($partner === null) {
            return;
        }

        $unread = PartnerMessage::query()
            ->where('partner_id', $partner->id)
            ->inbound()
            ->unread();

        $this->justReadMessageIds = $unread->pluck('id')->all();

        if ($this->justReadMessageIds !== []) {
            PartnerMessage::whereIn('id', $this->justReadMessageIds)->update(['read_at' => now()]);
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->record->partnerMessages())
            ->recordTitleAttribute('subject')
            ->emptyStateHeading($this->getPartner()?->email !== null
                ? 'No messages yet'
                : ($this->getPartner() !== null ? 'Partner has no email on file' : 'No partner assigned'))
            ->emptyStateDescription($this->getPartner()?->email !== null
                ? 'Use "Contact owner" or "Send claim email" above to start the conversation.'
                : ($this->getPartner() !== null
                    ? 'Add an email address to this partner to enable owner contact.'
                    : 'Assign a partner to this listing to enable owner contact.'))
            ->columns([
                Tables\Columns\IconColumn::make('direction')
                    ->label('')
                    ->icon(fn (string $state): string => $state === PartnerMessage::DIRECTION_INBOUND
                        ? 'heroicon-o-arrow-down-left'
                        : 'heroicon-o-arrow-up-right')
                    ->color(fn (string $state): string => $state === PartnerMessage::DIRECTION_INBOUND
                        ? 'success'
                        : 'gray'),
                Tables\Columns\TextColumn::make('subject')
                    ->wrap()
                    ->searchable()
                    ->weight(fn (PartnerMessage $record): ?FontWeight => in_array($record->id, $this->justReadMessageIds, true)
                        ? FontWeight::Bold
                        : null),
                Tables\Columns\TextColumn::make('template')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'claim_invite' => 'Claim invite',
                        default => 'Custom',
                    }),
                Tables\Columns\TextColumn::make('sentBy.name')
                    ->label('Sent by')
                    ->placeholder('System'),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('send_claim_email')
                    ->label('Send claim email')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (): bool => $this->getPartner()?->email !== null
                        && $this->getPartner()->claimed_at === null
                        && $this->getPartner()->claim_rejected_at === null)
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Send (or re-send) the "claim your listing" email to '.$this->getPartner()?->email)
                    ->action(function (): void {
                        $sent = app(ClaimInviteService::class)->invite($this->getPartner());

                        if ($sent) {
                            Notification::make()->title('Claim email sent')->success()->send();
                        } else {
                            Notification::make()->title('Could not send claim email')->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('contact_owner')
                    ->label('Contact owner')
                    ->icon('heroicon-o-envelope')
                    ->color('primary')
                    ->visible(fn (): bool => $this->getPartner()?->email !== null)
                    ->form([
                        Forms\Components\TextInput::make('subject')
                            ->required()
                            ->maxLength(255)
                            ->default(fn (): string => "About your listing on NamibWay: {$this->record->name}"),
                        Forms\Components\Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->rows(6),
                    ])
                    ->action(function (array $data): void {
                        $partner = $this->getPartner();

                        Mail::to($partner->email, $partner->name)
                            ->send(new PartnerContactMail($data['subject'], $data['body']));

                        PartnerMessage::create([
                            'partner_id' => $partner->id,
                            'listing_id' => $this->record->id,
                            'sent_by' => auth()->id(),
                            'direction' => PartnerMessage::DIRECTION_OUTBOUND,
                            'subject' => $data['subject'],
                            'body' => $data['body'],
                            'sent_at' => now(),
                        ]);

                        Notification::make()->title('Message sent to '.$partner->email)->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove log entry'),
            ])
            ->bulkActions([]);
    }

    public function render(): View
    {
        return view('livewire.listing-messages-panel');
    }

    private function getPartner(): ?Partner
    {
        return $this->record->partner;
    }
}
