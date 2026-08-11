<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ApiSetupGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'API Partner Setup';

    protected static ?string $title = 'API Partner Setup';

    protected static ?string $navigationGroup = 'Documentation';

    protected static string $view = 'filament.pages.api-setup-guide';
}
