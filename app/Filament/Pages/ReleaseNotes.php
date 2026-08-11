<?php

namespace App\Filament\Pages;

use App\Services\ReleaseVersion;
use Filament\Pages\Page;

/**
 * @phpstan-import-type ReleaseData from ReleaseVersion
 */
class ReleaseNotes extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Release Notes';

    protected static ?string $title = 'Release Notes';

    // The URL stays /admin/deploy-log: ReleaseNoteResource already owns
    // /admin/release-notes, and existing bookmarks keep working.
    protected static ?string $slug = 'deploy-log';

    protected static ?string $navigationGroup = 'Documentation';

    protected static string $view = 'filament.pages.release-notes';

    /** @var ReleaseData */
    public array $release;

    public function mount(): void
    {
        $this->release = ReleaseVersion::current();
    }
}
