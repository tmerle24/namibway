<?php

namespace App\Filament\Resources\ListingResource\RelationManagers;

use App\Mail\PartnerContactMail;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\PartnerMessage;
use App\Services\Enrichment\ClaimInviteService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

class PartnerMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'partnerMessages';

    protected static ?string $title = 'Messages';

    protected static ?string $icon = 'heroicon-o-envelope';

    // Nothing to talk to yet — the listing needs a partner (with an email
    // address) attached before the owner-contact thread means anything.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        /** @var Listing $ownerRecord */
        return $ownerRecord->partner_id !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
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
                    ->searchable(),
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
                            ->default(fn (): string => "About your listing on NamibWay: {$this->getListing()->name}"),
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
                            'listing_id' => $this->getListing()->id,
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

    private function getListing(): Listing
    {
        /** @var Listing $listing */
        $listing = $this->getOwnerRecord();

        return $listing;
    }

    private function getPartner(): ?Partner
    {
        return $this->getListing()->partner;
    }
}
