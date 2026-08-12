<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * How we set a customer website up, and what we tell the business.
 *
 * The people who need this are not the people who built it: content managers
 * and whoever is on the phone to a lodge owner. So it is written as the
 * conversation — what we do, what they have to do, what we cannot do yet — and
 * not as a description of the software.
 */
class WebsiteGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website Guide';

    protected static ?string $title = 'Website Guide';

    protected static ?string $navigationGroup = 'Documentation';

    protected static string $view = 'filament.pages.website-guide';
}
