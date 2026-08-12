<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteTermsResource\Pages;
use App\Models\WebsiteTerms;
use App\Sites\WebsiteTermsText;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Our own terms for the website product, edited here rather than in a config
 * string.
 *
 * A resource rather than a settings page, because these are versions and not
 * settings: a customer's acceptance is recorded against a moment, and editing
 * the one text over and over would make every past acceptance unanswerable.
 * Write a new version; publish it; the old one stays readable.
 *
 * Publishing is what makes a version the one linked from the foot of every
 * customer site. An unpublished row is a draft, which is the state the scaffold
 * starts in on purpose — it describes how the product works and has not been
 * read by a lawyer.
 */
class WebsiteTermsResource extends Resource
{
    protected static ?string $model = WebsiteTerms::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Website Terms';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $modelLabel = 'website terms version';

    protected static ?string $pluralModelLabel = 'website terms';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('warning')
                ->hiddenLabel()
                ->content('This text was written in-house to describe how the product actually works. '
                    .'It is a starting point for a lawyer, not a reviewed contract — do not publish a '
                    .'version that has not been read by one, and say so to anybody who asks.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('version')
                ->required()
                ->maxLength(20)
                ->helperText('Shown to the customer and stored beside their acceptance. "1.0", "1.1".'),

            Forms\Components\DatePicker::make('effective_at')
                ->label('In force from')
                ->helperText('The date it applies from, which is not the date it was written.'),

            Forms\Components\RichEditor::make('body')
                ->required()
                ->disableToolbarButtons(['attachFiles'])
                ->default(fn (): string => WebsiteTermsText::scaffold())
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('version')->searchable(),
                Tables\Columns\TextColumn::make('effective_at')->label('In force from')->date('j M Y')->placeholder('—'),
                Tables\Columns\IconColumn::make('published_at')
                    ->label('Published')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-pencil'),
                Tables\Columns\TextColumn::make('updated_at')->label('Changed')->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('publish')
                    ->label(fn (WebsiteTerms $record): string => $record->isPublished() ? 'Withdraw' : 'Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color(fn (WebsiteTerms $record): string => $record->isPublished() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (WebsiteTerms $record): string => $record->isPublished()
                        ? 'It stops being the version customers are shown. If it is the only published '
                            .'version, the Terms link disappears from every customer site rather than 404ing.'
                        : 'It becomes the version linked from the foot of every customer website, and the '
                            .'one recorded against every acceptance from now on. Only publish what a lawyer '
                            .'has read.')
                    ->action(fn (WebsiteTerms $record) => $record
                        ->forceFill(['published_at' => $record->isPublished() ? null : now()])
                        ->save()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsiteTerms::route('/'),
            'create' => Pages\CreateWebsiteTerms::route('/create'),
            'edit' => Pages\EditWebsiteTerms::route('/{record}/edit'),
        ];
    }
}
