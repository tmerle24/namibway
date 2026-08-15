<?php

namespace App\Filament\Support;

use App\Jobs\ImportInstagramPhotosJob;
use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Admin-only button that queues an Instagram photo import for a partner.
 *
 * Visible only when `partner->instagram` is filled. The import runs in the
 * background and sends a database notification when it finishes.
 */
class ImportInstagramPhotosAction
{
    public static function make(string $name = 'import_instagram_photos'): Action
    {
        return Action::make($name)
            ->label('Import from Instagram')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->visible(fn (Partner $record): bool => filled($record->instagram))
            ->form([
                TextInput::make('limit')
                    ->label('Maximum photos to import')
                    ->numeric()
                    ->default(12)
                    ->minValue(1)
                    ->maxValue(50)
                    ->helperText('Carousels count per image, so a 3-image post uses 3 slots.'),
            ])
            ->modalHeading(fn (Partner $record): string => 'Import photos from @'.(
                ImportInstagramPhotosJob::extractUsername($record->instagram ?? '') ?? 'Instagram'
            ))
            ->modalDescription(
                'Downloads recent posts from the public Instagram profile and adds '.
                'them to the partner gallery. The profile must be public. '.
                'Existing gallery images are kept — duplicates are not added twice.'
            )
            ->modalSubmitActionLabel('Import')
            ->action(function (Partner $record, array $data): void {
                $userId = auth()->id();

                ImportInstagramPhotosJob::dispatch(
                    $record,
                    (int) ($data['limit'] ?? 12),
                    is_numeric($userId) ? (int) $userId : null,
                );

                Notification::make()
                    ->title('Importing from Instagram')
                    ->body("A notification appears here when it's done.")
                    ->success()
                    ->send();
            });
    }
}
