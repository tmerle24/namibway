<?php

namespace App\Filament\Resources;

use App\Enums\ListingType;
use App\Filament\Resources\EnrichmentResource\Pages;
use App\Jobs\EnrichListingJob;
use App\Models\EnrichmentJob;
use App\Models\Listing;
use App\Models\Partner;
use App\Services\Enrichment\ClaimInviteService;
use App\Services\Enrichment\CompletionScoreService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The VisitNamibia Data Enrichment Dashboard. A second Filament resource
 * over the same Listing model as ListingResource — that one is plain CRUD,
 * this one is built around completion tracking, running/queuing the
 * enrichment pipeline, and reviewing what it found. Editing happens in a
 * slide-over rather than a full edit page, so the table stays the primary
 * surface.
 */
class EnrichmentResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static ?string $slug = 'enrichment';

    protected static ?string $navigationLabel = 'Data Enrichment';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $modelLabel = 'listing';

    protected static ?int $navigationSort = 0;

    /** 1-based tab indexes, matching the order of Tabs\Tab entries in form(). */
    private const TAB_BASIC = 1;

    private const TAB_CONTACT = 2;

    private const TAB_ADDRESS = 3;

    private const TAB_DESCRIPTION = 4;

    private const TAB_PHOTOS = 7;

    private const TAB_METADATA = 8;

    private const TAB_LOG = 9;

    /**
     * Which tab the edit modal should open on, stamped by editAction()'s
     * mutateRecordDataUsing() (which always runs before the form/Tabs render) and read by
     * Tabs::activeTab() below. A plain request-scoped static rather than routing this
     * through Filament's action arguments()/utility-injection: Tabs::activeTab() only
     * receives the base Component evaluation context, not an Action's own arguments, so
     * injecting `array $arguments` there throws (learned the hard way — 500 in production).
     */
    private static int $activeTab = self::TAB_BASIC;

    /** Which field should receive focus once its tab opens — see focusAttributes(). */
    private static ?string $activeField = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('enrichment_in_progress_banner')
                ->label('')
                ->content(new HtmlString(
                    '<div style="padding:8px 12px;background:#fef3c7;border-radius:6px;color:#92400e;">'
                    .'⏳ Enrichment is currently running for this listing — fields are locked until it finishes. '
                    .'Refresh in a moment.</div>'
                ))
                ->visible(fn (?Listing $record): bool => self::isEnriching($record))
                ->columnSpanFull(),

            Forms\Components\Tabs::make('Enrichment')
                ->columnSpanFull()
                ->disabled(fn (?Listing $record): bool => self::isEnriching($record))
                // Which tab opens active depends on which table column/action was clicked
                // (see editAction()/self::$activeTab) — falls back to Basic when opened
                // generically (row click, pencil icon).
                ->activeTab(fn (): int => self::$activeTab)
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Basic')
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(255)
                                ->autofocus()
                                ->extraInputAttributes(fn (): array => self::focusAttributes('name')),
                            Forms\Components\TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
                            Forms\Components\TextInput::make('ntb_number')
                                ->label('NTB number')
                                ->extraInputAttributes(fn (): array => self::focusAttributes('ntb_number')),
                            Forms\Components\Select::make('type')->label('Category')->options(ListingType::class)->required(),
                            Forms\Components\TextInput::make('region')
                                ->extraInputAttributes(fn (): array => self::focusAttributes('region')),
                        ])->columns(2),

                    Forms\Components\Tabs\Tab::make('Contact')
                        ->schema([
                            Forms\Components\TextInput::make('phone')
                                ->extraInputAttributes(fn (): array => self::focusAttributes('phone')),
                            Forms\Components\TextInput::make('contact_email')
                                ->label('Email')
                                ->email()
                                ->extraInputAttributes(fn (): array => self::focusAttributes('contact_email')),
                            Forms\Components\TextInput::make('website')
                                ->url()
                                ->extraInputAttributes(fn (): array => self::focusAttributes('website')),
                        ])->columns(2),

                    Forms\Components\Tabs\Tab::make('Address')
                        ->schema([
                            Forms\Components\Textarea::make('address')->columnSpanFull(),
                            Forms\Components\TextInput::make('latitude')
                                ->numeric()
                                ->extraInputAttributes(fn (): array => self::focusAttributes('latitude')),
                            Forms\Components\TextInput::make('longitude')->numeric(),
                        ])->columns(2),

                    Forms\Components\Tabs\Tab::make('Description')
                        ->schema([
                            Forms\Components\Textarea::make('short_description')->rows(2)->helperText('~80 words, used on cards/previews'),
                            Forms\Components\Textarea::make('description')
                                ->label('Long description')
                                ->rows(6)
                                ->helperText('~250 words, used on the listing page')
                                ->extraInputAttributes(fn (): array => self::focusAttributes('description')),
                            Forms\Components\TextInput::make('meta_title')->maxLength(60),
                            Forms\Components\TextInput::make('seo_description')->maxLength(160),
                        ])->columns(1),

                    Forms\Components\Tabs\Tab::make('Activities')
                        ->schema([
                            Forms\Components\TagsInput::make('activities')->placeholder('Add an activity and press enter'),
                        ]),

                    Forms\Components\Tabs\Tab::make('Facilities')
                        ->schema([
                            Forms\Components\TagsInput::make('facilities')->placeholder('Add a facility and press enter'),
                            Forms\Components\TagsInput::make('languages')->placeholder('Add a language and press enter'),
                        ]),

                    Forms\Components\Tabs\Tab::make('Photos')
                        ->schema([
                            Forms\Components\FileUpload::make('image')->label('Hero image')->image()->disk('public')->directory('listings')->imageEditor(),
                            Forms\Components\FileUpload::make('gallery')->image()->multiple()->reorderable()->disk('public')->directory('listings/gallery'),
                        ]),

                    Forms\Components\Tabs\Tab::make('Metadata')
                        ->schema([
                            Forms\Components\Placeholder::make('enrichment_score')
                                ->label('Completion score')
                                ->content(fn (?Listing $record): string => $record ? "{$record->enrichment_score}% ({$record->enrichment_status})" : '—'),
                            Forms\Components\Placeholder::make('data_source')
                                ->label('Data source')
                                ->content(fn (?Listing $record): string => $record ? ($record->data_source ?? '—') : '—'),
                            Forms\Components\Placeholder::make('confidence')
                                ->content(fn (?Listing $record): string => $record && $record->confidence !== null ? "{$record->confidence}%" : '—'),
                            Forms\Components\Placeholder::make('enriched_by')
                                ->label('Enriched by')
                                ->content(fn (?Listing $record): string => $record ? ($record->enriched_by ?? '—') : '—'),
                            Forms\Components\Placeholder::make('last_enriched_at')
                                ->label('Last enrichment')
                                ->content(fn (?Listing $record): string => $record && $record->last_enriched_at ? $record->last_enriched_at->diffForHumans() : 'never'),
                            Forms\Components\DateTimePicker::make('verified_at'),
                        ])->columns(2),

                    Forms\Components\Tabs\Tab::make('Log')
                        ->schema([
                            Forms\Components\Placeholder::make('activity_log')
                                ->label('')
                                ->content(fn (?Listing $record): HtmlString => self::activityLogHtml($record)),
                        ]),
                ]),
        ]);
    }

    private static function isEnriching(?Listing $record): bool
    {
        if ($record === null) {
            return false;
        }

        $job = $record->relationLoaded('latestEnrichmentJob') ? $record->latestEnrichmentJob : $record->latestEnrichmentJob()->first();

        return $job !== null && $job->finished_at === null;
    }

    private static function activityLogHtml(?Listing $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }

        $jobs = $record->enrichmentJobs()->latest()->limit(25)->get();

        if ($jobs->isEmpty()) {
            return new HtmlString('<em>No activity yet.</em>');
        }

        $rows = $jobs->map(function (EnrichmentJob $job): string {
            $statusHtml = $job->success
                ? '<span style="color:#16a34a">success</span>'
                : '<span style="color:#dc2626">'.($job->finished_at === null ? 'running' : 'failed').'</span>';

            $fieldsHtml = $job->fields_changed
                ? ' — fields: '.e(implode(', ', $job->fields_changed))
                : '';

            $errorHtml = (! $job->success && $job->finished_at !== null && $job->log)
                ? '<br><span style="color:#dc2626;font-size:12px">'.e(Str::limit($job->log, 200)).'</span>'
                : '';

            return sprintf(
                '<div style="padding:6px 0;border-bottom:1px solid #e5e5e5;">%s — <strong>%s</strong> by %s — %s%s%s</div>',
                $job->created_at->format('d M Y H:i'),
                e($job->source),
                e($job->actor ?? 'unknown'),
                $statusHtml,
                $fieldsHtml,
                $errorHtml,
            );
        });

        return new HtmlString($rows->implode(''));
    }

    /**
     * Native autofocus is unreliable on inputs revealed by a Livewire/Alpine modal
     * transition (the browser's autofocus pass often runs before the element is actually
     * focusable), so every field that can be a click-to-edit target retries focus itself
     * via x-init once it matches self::$activeField (set by editAction()'s
     * mutateRecordDataUsing(), same timing as self::$activeTab).
     *
     * @return array<string, string>
     */
    private static function focusAttributes(string $field): array
    {
        return self::$activeField === $field
            ? ['x-init' => 'setTimeout(() => $el.focus(), 350)']
            : [];
    }

    /**
     * A slide-over EditAction pre-armed to open on a given form tab and field (see the
     * Tabs' ->activeTab() closure and focusAttributes() in form()) and to save via
     * saveManualEdit(). $name must be unique per instance — separate columns that should
     * focus different fields on the same tab (e.g. website vs. email) need their own
     * instance, even though they share $tab.
     */
    private static function editAction(int $tab, string $name, ?string $field = null): Tables\Actions\EditAction
    {
        return Tables\Actions\EditAction::make($name)
            ->slideOver()
            ->mutateRecordDataUsing(fn (array $data, Listing $record): array => self::hydrateFormData($data, $record, $tab, $field))
            ->using(fn (Listing $record, array $data): Listing => self::saveManualEdit($record, $data));
    }

    /**
     * name/description/short_description are Spatie-translatable JSON columns (e.g.
     * {"en": "..."}) — without this, the raw array reaches the form and renders as
     * "[object Object]" instead of editable text. Also stamps self::$activeTab/
     * self::$activeField so the Tabs component and the clicked field (see form()) get
     * the right tab open and the right input focused.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function hydrateFormData(array $data, Listing $record, int $tab, ?string $field): array
    {
        self::$activeTab = $tab;
        self::$activeField = $field;

        foreach (['name', 'description', 'short_description'] as $translatable) {
            $data[$translatable] = $record->getTranslation($translatable, 'en', useFallbackLocale: false);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private static function saveManualEdit(Listing $record, array $data): Listing
    {
        foreach (['name', 'description', 'short_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $record->setTranslation($field, 'en', (string) $data[$field]);
                unset($data[$field]);
            }
        }

        $record->fill($data);

        $changedFields = array_keys($record->getDirty());

        if ($changedFields !== []) {
            $record->enriched_by = auth()->user()->name ?? 'Admin';
        }

        $record->save();

        if ($changedFields !== []) {
            EnrichmentJob::create([
                'listing_id' => $record->id,
                'started_at' => now(),
                'finished_at' => now(),
                'source' => 'manual_edit',
                'actor' => $record->enriched_by,
                'success' => true,
                'fields_changed' => $changedFields,
            ]);

            app(CompletionScoreService::class)->recalculate($record);
        }

        return $record;
    }

    public static function table(Table $table): Table
    {
        // Built once and shared across every column that should open the edit modal on that
        // tab (and focused on that field, see focusAttributes()) — clicking a specific cell
        // (e.g. the website icon) jumps straight to it instead of always landing on Basic.
        // Row clicks that don't land on a bound column (recordAction('edit') below) still
        // default to $editBasic.
        $editBasic = self::editAction(self::TAB_BASIC, 'edit', 'name');
        $editNtbNumber = self::editAction(self::TAB_BASIC, 'edit_ntb_number', 'ntb_number');
        $editRegion = self::editAction(self::TAB_BASIC, 'edit_region', 'region');
        $editWebsite = self::editAction(self::TAB_CONTACT, 'edit_website', 'website');
        $editEmail = self::editAction(self::TAB_CONTACT, 'edit_email', 'contact_email');
        $editPhone = self::editAction(self::TAB_CONTACT, 'edit_phone', 'phone');
        $editAddress = self::editAction(self::TAB_ADDRESS, 'edit_address', 'latitude');
        $editDescription = self::editAction(self::TAB_DESCRIPTION, 'edit_description', 'description');
        $editPhotos = self::editAction(self::TAB_PHOTOS, 'edit_photos');
        $editMetadata = self::editAction(self::TAB_METADATA, 'edit_metadata');
        $editLog = self::editAction(self::TAB_LOG, 'edit_log');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('enrichment_status')
                    ->label('Status')
                    ->badge()
                    ->action($editMetadata)
                    ->color(fn (string $state): string => match ($state) {
                        'complete' => 'success',
                        'partial' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('enrichment_score')
                    ->label('Completion')
                    ->sortable()
                    ->action($editMetadata)
                    ->formatStateUsing(fn (int $state): string => "{$state}%")
                    ->color(fn (int $state): string => match (true) {
                        $state >= 90 => 'success',
                        $state >= 60 => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->wrap()
                    ->limit(40)
                    ->action($editBasic),
                Tables\Columns\TextColumn::make('ntb_number')
                    ->label('NTB #')
                    ->searchable()
                    ->action($editNtbNumber)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('type')
                    ->label('Category')
                    ->badge()
                    ->action($editBasic)
                    ->sortable(),
                Tables\Columns\TextColumn::make('region')
                    ->searchable()
                    ->action($editRegion)
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_website')
                    ->label('Website')
                    ->boolean()
                    ->action($editWebsite)
                    ->getStateUsing(fn (Listing $record): bool => filled($record->website))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('website', 'ilike', "%{$search}%")),
                Tables\Columns\IconColumn::make('has_email')
                    ->label('Email')
                    ->boolean()
                    ->action($editEmail)
                    ->getStateUsing(fn (Listing $record): bool => filled($record->contact_email))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('contact_email', 'ilike', "%{$search}%")),
                Tables\Columns\IconColumn::make('has_phone')
                    ->label('Phone')
                    ->boolean()
                    ->action($editPhone)
                    ->getStateUsing(fn (Listing $record): bool => filled($record->phone))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('phone', 'ilike', "%{$search}%")),
                Tables\Columns\IconColumn::make('has_description')
                    ->label('Description')
                    ->boolean()
                    ->action($editDescription)
                    ->getStateUsing(fn (Listing $record): bool => filled($record->getTranslation('description', 'en', useFallbackLocale: false))),
                Tables\Columns\IconColumn::make('has_photos')
                    ->label('Photos')
                    ->boolean()
                    ->action($editPhotos)
                    ->getStateUsing(fn (Listing $record): bool => filled($record->image)),
                Tables\Columns\IconColumn::make('has_gps')
                    ->label('GPS')
                    ->boolean()
                    ->action($editAddress)
                    ->getStateUsing(fn (Listing $record): bool => $record->latitude !== null && $record->longitude !== null),
                Tables\Columns\TextColumn::make('claim_status')
                    ->label('Claimed')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'claimed' => 'success',
                        'rejected' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_run_status')
                    ->label('Last run')
                    ->action($editLog)
                    ->getStateUsing(fn (Listing $record): string => match (true) {
                        $record->latestEnrichmentJob === null => 'none',
                        $record->latestEnrichmentJob->finished_at === null => 'running',
                        $record->latestEnrichmentJob->success => 'ok',
                        default => 'failed',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ok' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'ok' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        'running' => 'heroicon-o-clock',
                        default => 'heroicon-o-minus',
                    })
                    ->tooltip(fn (Listing $record): ?string => $record->latestEnrichmentJob !== null && ! $record->latestEnrichmentJob->success
                        ? $record->latestEnrichmentJob->log
                        : null),
                Tables\Columns\TextColumn::make('last_enriched_at')
                    ->label('Last enriched')
                    ->since()
                    ->sortable()
                    ->action($editMetadata)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable()
                    ->action($editMetadata)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('latestEnrichmentJob'))
            ->poll('10s')
            ->recordAction('edit')
            ->defaultSort('enrichment_score', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Category')->options(ListingType::class),
                Tables\Filters\SelectFilter::make('region')
                    ->options(fn (): array => Listing::query()->whereNotNull('region')->distinct()->orderBy('region')->pluck('region', 'region')->all()),
                Tables\Filters\SelectFilter::make('completion')
                    ->label('Completion %')
                    ->options(['green' => '90–100% (green)', 'yellow' => '60–89% (yellow)', 'red' => '0–59% (red)'])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'green' => $query->where('enrichment_score', '>=', 90),
                            'yellow' => $query->whereBetween('enrichment_score', [60, 89]),
                            'red' => $query->where('enrichment_score', '<', 60),
                            default => $query,
                        };
                    }),
                Tables\Filters\TernaryFilter::make('has_website')
                    ->label('Has website')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('website')->where('website', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('website')->orWhere('website', '')),
                    ),
                Tables\Filters\TernaryFilter::make('has_email')
                    ->label('Has email')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('contact_email')->where('contact_email', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('contact_email')->orWhere('contact_email', '')),
                    ),
                Tables\Filters\TernaryFilter::make('has_photos')
                    ->label('Has photos')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('image')->where('image', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('image')->orWhere('image', '')),
                    ),
                Tables\Filters\TernaryFilter::make('has_description')
                    ->label('Has description')
                    ->queries(
                        true: fn (Builder $query) => $query->whereRaw("description->>'en' IS NOT NULL AND description->>'en' != ''"),
                        false: fn (Builder $query) => $query->whereRaw("description->>'en' IS NULL OR description->>'en' = ''"),
                    ),
                Tables\Filters\SelectFilter::make('claim_status')
                    ->label('Claimed')
                    ->options(['unclaimed' => 'Unclaimed', 'pending' => 'Pending', 'claimed' => 'Claimed', 'rejected' => 'Rejected']),
                Tables\Filters\TernaryFilter::make('verified')
                    ->label('Verified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('verified_at'),
                        false: fn (Builder $query) => $query->whereNull('verified_at'),
                    ),
                Tables\Filters\TernaryFilter::make('ai_enriched')
                    ->label('AI enriched')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('fieldStatuses', fn ($q) => $q->where('status', 'ai_generated')),
                        false: fn (Builder $query) => $query->whereDoesntHave('fieldStatuses', fn ($q) => $q->where('status', 'ai_generated')),
                    ),
                Tables\Filters\TernaryFilter::make('never_enriched')
                    ->label('Never enriched')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('last_enriched_at'),
                        false: fn (Builder $query) => $query->whereNotNull('last_enriched_at'),
                    ),
            ])
            ->actions([
                $editBasic,
                Tables\Actions\Action::make('ai_enrich')
                    ->label('AI Enrich')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Runs the full enrichment pipeline (website, structured extraction, photos, description) for this listing.')
                    ->action(function (Listing $record): void {
                        EnrichListingJob::dispatch($record->id);
                        Notification::make()
                            ->title('Enrichment queued')
                            ->body('Running in the background — the "Last run" column and score update automatically within ~10s of finishing.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('find_website')
                        ->label('Find Website')
                        ->icon('heroicon-o-globe-alt')
                        ->action(fn (Collection $records) => self::queueEnrichment($records, ['website'])),
                    Tables\Actions\BulkAction::make('ai_enrich')
                        ->label('AI Enrich')
                        ->icon('heroicon-o-sparkles')
                        ->color('warning')
                        ->action(fn (Collection $records) => self::queueEnrichment($records, null)),
                    Tables\Actions\BulkAction::make('find_gps')
                        ->label('Find GPS')
                        ->icon('heroicon-o-map-pin')
                        ->action(fn (Collection $records) => self::queueEnrichment($records, ['website', 'ai_extract'])),
                    Tables\Actions\BulkAction::make('generate_description')
                        ->label('Generate Description')
                        ->icon('heroicon-o-document-text')
                        ->action(fn (Collection $records) => self::queueEnrichment($records, ['description'])),
                    Tables\Actions\BulkAction::make('invite_owner')
                        ->label('Invite Owner')
                        ->icon('heroicon-o-envelope')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, ClaimInviteService $inviter): void {
                            $sent = 0;

                            /** @var Listing $record */
                            foreach ($records as $record) {
                                $partner = $record->partner;

                                if ($partner instanceof Partner && $inviter->invite($partner)) {
                                    $sent++;
                                }
                            }

                            Notification::make()->title("Invited {$sent} owner(s)")->success()->send();
                        }),
                    Tables\Actions\BulkAction::make('export_csv')
                        ->label('Export CSV')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(fn (Collection $records) => self::exportCsv($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @param  Collection<int, Listing>  $records
     * @param  list<string>|null  $steps
     */
    private static function queueEnrichment(Collection $records, ?array $steps): void
    {
        /** @var Listing $record */
        foreach ($records as $record) {
            EnrichListingJob::dispatch($record->id, $steps);
        }

        Notification::make()
            ->title("Queued {$records->count()} listing(s) for enrichment")
            ->body('Running in the background — the table refreshes automatically as jobs finish; check the "Last run" column for errors.')
            ->success()
            ->send();
    }

    /** @param  Collection<int, Listing>  $records */
    private static function exportCsv(Collection $records): StreamedResponse
    {
        $columns = ['id', 'ntb_number', 'name', 'type', 'region', 'website', 'contact_email', 'phone', 'address', 'enrichment_score', 'enrichment_status', 'claim_status', 'last_enriched_at'];

        $csv = implode(',', $columns)."\n";

        /** @var Listing $record */
        foreach ($records as $record) {
            $csv .= implode(',', array_map(
                fn (string $column) => '"'.str_replace('"', '""', (string) match ($column) {
                    'name' => $record->getTranslation('name', 'en', useFallbackLocale: true),
                    'type' => $record->type->value,
                    default => $record->{$column},
                }).'"',
                $columns
            ))."\n";
        }

        return Response::streamDownload(function () use ($csv) {
            echo $csv;
        }, 'listings-enrichment-export.csv', ['Content-Type' => 'text/csv']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEnrichment::route('/'),
        ];
    }
}
