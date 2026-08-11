<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Note;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * One document: what it is, the thing itself, and what people have said about
 * it.
 *
 * A page with a view rather than an infolist, for the same reason ViewCustomer
 * is one — a wiki page has to be readable as a page, and the discussion has to
 * be visible underneath it without a second click.
 */
class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    protected static string $view = 'filament.pages.view-document';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->label('Open file')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (Document $record): string => route('documents.download', $record))
                ->openUrlInNewTab()
                ->visible(fn (Document $record): bool => $record->isFile()),
            Actions\EditAction::make(),
            Action::make('comment')
                ->label('Add a comment')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Post comment')
                ->form([
                    Textarea::make('body')
                        ->label('Comment')
                        ->required()
                        ->rows(4)
                        ->maxLength(2000)
                        ->placeholder('Superseded by the 2027 version. Ask Till before sending this to a lodge.'),
                ])
                ->action(function (array $data): void {
                    /** @var User|null $author */
                    $author = auth()->user();

                    Note::write($this->getRecord(), (string) $data['body'], $author);

                    Notification::make()->success()->title('Comment added')->send();
                }),
        ];
    }

    /**
     * @return Collection<int, Note>
     */
    public function comments(): Collection
    {
        /** @var Document $document */
        $document = $this->getRecord();

        return $document->notes()->limit(200)->get();
    }
}
