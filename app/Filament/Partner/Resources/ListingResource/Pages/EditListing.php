<?php

namespace App\Filament\Partner\Resources\ListingResource\Pages;

use App\Filament\Partner\Resources\ListingResource;
use App\Filament\Support\BookingConnectorSchema;
use App\Models\Listing;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

class EditListing extends EditRecord
{
    use Translatable;

    protected static string $resource = ListingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Listing $record */
        $record = $this->getRecord();

        $partnerId = $record->partner_id;

        return BookingConnectorSchema::persistPartnerFields($data, $partnerId ? (int) $partnerId : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
            Actions\Action::make('back')
                ->label('Back to listings')
                ->url(ListingResource::getUrl())
                ->color('gray'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
