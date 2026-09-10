<?php

namespace App\Filament\Pages;

use App\Models\Site;
use App\Sites\Import\SitePackage;
use App\Sites\Import\SitePackageImporter;
use App\Sites\Import\SitePackagePlan;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * One ZIP in, a finished website out: site.json plus the pictures and clips
 * it names (SITE_PACKAGE.md). Built so a site can be put together outside the
 * panel — by hand or with Claude Code — and arrive in one upload.
 *
 * Dry run first, like the Excel import: "Check" shows which partner, listing
 * and website the package matched and every field it would change; "Import"
 * re-checks the same file and writes it.
 */
class SitePackageImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Import website';

    protected static ?string $title = 'Import a website package';

    protected static string $view = 'filament.pages.site-package-import';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public ?string $siteUrl = null;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('package')
                    ->label('Website package (.zip)')
                    ->helperText('site.json and the pictures and videos it names. Up to 12 MB — shrink photos to about 1600 px first.')
                    ->disk('local')
                    ->directory('site-packages')
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'multipart/x-zip'])
                    ->maxSize(12 * 1024)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (): void {
                        $this->preview = null;
                        $this->siteUrl = null;
                    }),
            ])
            ->statePath('data');
    }

    public function check(): void
    {
        $plan = $this->run(fn (SitePackageImporter $importer, SitePackage $package) => $importer->plan($package));

        if ($plan !== null) {
            Notification::make()->title('Package checked — nothing has been saved yet')->info()->send();
        }
    }

    public function import(): void
    {
        $plan = $this->run(fn (SitePackageImporter $importer, SitePackage $package) => $importer->apply($package));

        if ($plan === null) {
            return;
        }

        if (! $plan->written) {
            Notification::make()->title('Nothing imported')->body('Fix the problems listed below and upload the package again.')->danger()->send();

            return;
        }

        $this->siteUrl = Site::find($plan->siteId)?->publicUrl();

        Notification::make()->title('Website imported')->success()->send();
    }

    public function checkAction(): Action
    {
        return Action::make('check')
            ->label('Check package')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->action(fn () => $this->check());
    }

    public function importAction(): Action
    {
        return Action::make('import')
            ->label('Import')
            ->icon('heroicon-o-arrow-down-tray')
            ->requiresConfirmation()
            ->modalHeading('Import this website')
            ->modalDescription('Creates what is missing and updates what exists. Nothing is deleted: fields the package leaves out, pictures already on the site and bands it does not name stay as they are.')
            ->modalSubmitActionLabel('Import')
            ->action(fn () => $this->import());
    }

    /**
     * @param  callable(SitePackageImporter, SitePackage): SitePackagePlan  $step
     */
    private function run(callable $step): ?SitePackagePlan
    {
        $path = $this->uploadedPath();

        if ($path === null) {
            return null;
        }

        try {
            $package = SitePackage::open($path);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }

        try {
            $plan = $step(app(SitePackageImporter::class), $package);
        } finally {
            $package->close();
        }

        $this->preview = $plan->toArray();

        return $plan;
    }

    private function uploadedPath(): ?string
    {
        $state = $this->getForm('form')?->getState()['package'] ?? null;

        // FileUpload hands back either the stored path or a uuid-keyed array of them.
        if (is_array($state)) {
            $state = $state === [] ? null : reset($state);
        }

        if (! is_string($state) || $state === '') {
            Notification::make()->title('Please upload a package first')->warning()->send();

            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($state)) {
            Notification::make()->title('The uploaded file is gone — please upload it again')->danger()->send();

            return null;
        }

        return $disk->path($state);
    }
}
