<?php

namespace App\Filament\Partner\Resources\ListingResource\Pages;

use App\Filament\Concerns\HasFormActionsInHeader;
use App\Filament\Partner\Resources\ListingResource;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\CreateWebsiteAction;
use App\Filament\Support\EditActionButtonsAction;
use App\Filament\Support\EditBlocksAction;
use App\Filament\Support\EditContactChannelsAction;
use App\Filament\Support\EditHeroAction;
use App\Filament\Support\ManageShopProductsAction;
use App\Filament\Support\EditLegalTextAction;
use App\Filament\Support\EditPagesAction;
use App\Filament\Support\EditSiteImagesAction;
use App\Filament\Support\EditSiteLogoAction;
use App\Filament\Support\EditTypographyAction;
use App\Filament\Support\OrderWebsiteAction;
use App\Filament\Support\ViewListingAction;
use App\Models\Listing;
use App\Services\Enrichment\OsmLocationFinder;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\EditRecord\Concerns\Translatable;

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

        $partnerId = $record->partner_id;

        $data = app(OsmLocationFinder::class)->fillMissingCoordinates($data, $data['name'] ?? $record->name);

        return BookingConnectorSchema::persistPartnerFields($data, $partnerId ? (int) $partnerId : null);
    }

    protected function getHeaderActions(): array
    {
        return $this->withFormActions([
            ViewListingAction::header(),
            // The link, but not the build button: that one is switched off for
            // owners until the subscription is active, and a disabled button in
            // a page header reads as broken rather than as forthcoming. The
            // order button is what an owner without one presses instead.
            OrderWebsiteAction::header(),
            CreateWebsiteAction::visitHeader(),
            // The content, the opening screen, the logo and the legal pages:
            // all of it is the owner's, not ours, so the owner sets it here
            // rather than by asking us.
            EditBlocksAction::header(),
            EditPagesAction::header(),
            EditSiteImagesAction::header(),
            ManageShopProductsAction::header(),
            EditHeroAction::header(),
            EditSiteLogoAction::header(),
            EditTypographyAction::header(),
            EditContactChannelsAction::header(),
            // Whether visitors are invited to call, message or book is the
            // business's decision, not ours — so the owner has the switches.
            EditActionButtonsAction::header(),
            EditLegalTextAction::header(),
            Actions\LocaleSwitcher::make(),
            Actions\Action::make('back')
                ->label('Back to listings')
                ->url(ListingResource::getUrl())
                ->color('gray'),
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
