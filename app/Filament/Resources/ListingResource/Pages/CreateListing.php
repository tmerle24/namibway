<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Resources\ListingResource;
use App\Filament\Support\BookingConnectorSchema;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateListing extends CreateRecord
{
    use Translatable;

    protected static string $resource = ListingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $partnerId = $data['partner_id'] ?? null;

        return BookingConnectorSchema::persistPartnerFields($data, $partnerId ? (int) $partnerId : null);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
        ];
    }
}
