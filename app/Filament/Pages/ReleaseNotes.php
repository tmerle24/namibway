<?php

namespace App\Filament\Pages;

use App\Services\ReleaseVersion;
use Filament\Pages\Page;

class ReleaseNotes extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Release Notes';

    protected static ?string $title = 'Release Notes';

    protected static ?string $navigationGroup = 'Documentation';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.release-notes';

    public array $release;

    public function mount(): void
    {
        $this->release = ReleaseVersion::current();
    }
}
