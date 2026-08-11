<?php

namespace App\Filament\Pages;

use App\Support\MarketingMaterial as Material;
use Filament\Pages\Page;

/**
 * Download point for the printed material, so whoever is going out to prospect
 * does not have to be handed a PDF over WhatsApp or dig one out of the repo.
 *
 * There is no upload here on purpose: the flyers are built from source in
 * marketing/ and committed, so a file dropped into the admin panel would be the
 * one nobody can rebuild and everybody keeps printing. Material that genuinely
 * is a file rather than a build artifact belongs under Documents
 * (App\Filament\Resources\DocumentResource), which is where uploads go.
 */
class MarketingMaterial extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Marketing material';

    protected static ?string $title = 'Marketing material';

    protected static ?string $navigationGroup = 'Documentation';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.marketing-material';

    /** @var list<array<string, mixed>> */
    public array $material;

    public function mount(): void
    {
        $this->material = Material::all();
    }
}
