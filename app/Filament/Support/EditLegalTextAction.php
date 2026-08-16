<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Sites\LegalText;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * Privacy, the legal notice and the copyright line — editable by whoever the
 * site is about.
 *
 * These three are the one part of a generated site that is not ours to keep
 * writing. We write the first version because a footer pointing at nothing is
 * not publishable, but the business is who they belong to and who is liable for
 * them, so the owner reaches the same editor the team does — and reaches it
 * from the partner panel, not by asking us.
 *
 * Filament keeps three Action classes that share their configuration methods
 * and no useful ancestor. Same answer as CreateWebsiteAction: the shape lives
 * in configure() once, and these methods only choose which class it is poured
 * into.
 */
class EditLegalTextAction
{
    /**
     * Inside the admin's Website tab, where the rest of the site's controls are.
     */
    public static function make(string $name = 'edit_legal'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    /**
     * In a record page's header — the partner panel's listing form is not
     * tabbed, so this is where an owner finds it.
     */
    public static function header(string $name = 'edit_legal'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => SiteResolver::for($record) !== null);
    }

    /**
     * @template T of FormAction|PageAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(FormAction|PageAction $action): FormAction|PageAction
    {
        return $action
            ->label('Privacy, legal notice & copyright')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->modalHeading('The legal pages of your website')
            ->modalDescription('These three belong to your business, not to us. We have written a first '
                .'version so the site can go live — correct anything that is wrong for you.')
            ->modalSubmitActionLabel('Save')
            // Filled from the defaults rather than from the columns, so the
            // editor always opens on the text the visitor is actually being
            // shown — never on an empty box above a page full of words.
            ->fillForm(function (Listing|Partner|null $record): array {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return [];
                }

                return [
                    'legal_copyright' => LegalText::copyright($site),
                    'legal_privacy' => LegalText::privacy($site),
                    'legal_imprint' => LegalText::imprint($site),
                ];
            })
            ->form([
                Forms\Components\TextInput::make('legal_copyright')
                    ->label('Copyright line')
                    ->helperText('Shown at the foot of every page, after the year.')
                    ->maxLength(255),

                Forms\Components\Textarea::make('legal_privacy')
                    ->label('Privacy')
                    ->helperText('Plain text. A blank line starts a new paragraph.')
                    ->rows(12),

                Forms\Components\Textarea::make('legal_imprint')
                    ->label('Legal notice')
                    ->helperText('Who is behind the site, and who is responsible for what.')
                    ->rows(12),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                $site->fill([
                    'legal_copyright' => $data['legal_copyright'] ?? null,
                    'legal_privacy' => $data['legal_privacy'] ?? null,
                    'legal_imprint' => $data['legal_imprint'] ?? null,
                ])->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }
}
