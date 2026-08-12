<?php

namespace App\Filament\Resources\ListingResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Resources\ListingResource;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\ViewListingAction;
use App\Models\Listing;
use App\Services\Enrichment\OsmLocationFinder;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;
use Illuminate\Contracts\Support\Htmlable;

class EditListing extends EditRecord
{
    use HasFormActionsInHeader;
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

        $partnerId = $data['partner_id'] ?? $record->partner_id;

        $data = app(OsmLocationFinder::class)->fillMissingCoordinates($data, $data['name'] ?? $record->name);

        return BookingConnectorSchema::persistPartnerFields($data, $partnerId ? (int) $partnerId : null);
    }

    // Editors need to know which listing they're on at a glance — the default
    // "Edit listing" heading is the same on every row.
    public function getTitle(): string|Htmlable
    {
        /** @var Listing $record */
        $record = $this->getRecord();

        return $record->name;
    }

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            ViewListingAction::header(),
            Actions\LocaleSwitcher::make(),
            Actions\DeleteAction::make(),
        ]);
    }
}
