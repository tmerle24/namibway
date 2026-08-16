<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * Configure the contact form that appears on the website.
 *
 * Edits the EnquiryBlock payload directly — the form type, the channel,
 * the heading, the intro text, and the button label. Surfaces as a single
 * focused button rather than sending the user through the full block editor.
 */
class EditEnquiryFormAction
{
    public static function make(string $name = 'edit_enquiry_form'): FormAction
    {
        return FormAction::make($name)
            ->label('Contact form')
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->modalHeading('Contact form')
            ->modalDescription('What the contact form on this website asks for and how visitors send it.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('lg')
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null)
            ->fillForm(function (Listing|Partner|null $record): array {
                $block = $record !== null ? self::block(SiteResolver::for($record)) : null;
                $data = $block !== null ? ($block->data ?? []) : [];

                return [
                    'form_type' => $data['form_type'] ?? EnquiryFormType::StayRequest->value,
                    'channel' => $data['channel'] ?? EnquiryBlock::CHANNEL_EMAIL,
                    'heading' => $data['heading'] ?? null,
                    'button_label' => $data['button_label'] ?? null,
                    'intro' => $data['intro'] ?? null,
                ];
            })
            ->form([
                Forms\Components\Select::make('form_type')
                    ->label('Form type')
                    ->options(EnquiryFormType::options())
                    ->native(false)
                    ->required()
                    ->helperText('One form per site — pick the one that matches what you are selling.'),

                Forms\Components\Select::make('channel')
                    ->label('How visitors send it')
                    ->options([
                        EnquiryBlock::CHANNEL_EMAIL => 'Email — the form posts to us, you answer by email',
                        EnquiryBlock::CHANNEL_WHATSAPP => 'WhatsApp — fields become a pre-filled WhatsApp message',
                    ])
                    ->native(false)
                    ->required(),

                Forms\Components\TextInput::make('heading')
                    ->label('Heading')
                    ->placeholder(fn (Forms\Get $get): string => EnquiryFormType::tryFrom((string) ($get('form_type') ?? ''))
                        ?->heading()
                        ?? EnquiryFormType::StayRequest->heading())
                    ->maxLength(120),

                Forms\Components\TextInput::make('button_label')
                    ->label('Button label')
                    ->placeholder(fn (Forms\Get $get): string => EnquiryFormType::tryFrom((string) ($get('form_type') ?? ''))
                        ?->buttonLabel()
                        ?? EnquiryFormType::StayRequest->buttonLabel())
                    ->maxLength(24)
                    ->helperText('Short — this lands in a small button on a phone.'),

                Forms\Components\Textarea::make('intro')
                    ->label('Intro text')
                    ->placeholder('Optional sentence above the form fields.')
                    ->rows(2)
                    ->maxLength(300),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record !== null ? SiteResolver::for($record) : null;

                if ($site === null) {
                    return;
                }

                $block = self::block($site);

                if ($block === null) {
                    return;
                }

                $block->update([
                    'data' => array_merge($block->data ?? [], [
                        'form_type' => $data['form_type'],
                        'channel' => $data['channel'],
                        'heading' => filled($data['heading'] ?? null) ? trim((string) $data['heading']) : null,
                        'button_label' => filled($data['button_label'] ?? null) ? trim((string) $data['button_label']) : null,
                        'intro' => filled($data['intro'] ?? null) ? trim((string) $data['intro']) : null,
                    ]),
                ]);

                Notification::make()->title('Saved')->success()->send();
            });
    }

    /**
     * Find the first enquiry block across all pages of the site.
     */
    private static function block(?Site $site): ?SiteBlock
    {
        if ($site === null) {
            return null;
        }

        foreach ($site->pages as $page) {
            $block = $page->blocks()->where('type', 'enquiry')->first();

            if ($block !== null) {
                return $block;
            }
        }

        return null;
    }
}
