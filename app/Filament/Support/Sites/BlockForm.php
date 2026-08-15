<?php

namespace App\Filament\Support\Sites;

use App\Models\Site;
use App\Models\SiteImage;
use App\Sites\BlockRegistry;
use App\Sites\Blocks\EnquiryBlock;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * The fields behind each block type, for the panel.
 *
 * **These live here and not on the block classes on purpose.** `App\Sites\Blocks`
 * is domain code serving the public renderer; a Filament component in there
 * would put the panel on the path of every page we serve. The cost of the split
 * is that a field could drift from the payload the renderer reads — which is
 * why `BlockEditorTest` walks every type in the registry and asserts that it has
 * a form and that every field on it is a key the type's own rules() know. A test
 * rather than a sentence asking somebody to remember.
 *
 * What a field may be is bounded by the same rule as everything else here: only
 * the keys a definition names in richTextFields() get a rich editor, and
 * everything else is plain text the view escapes.
 */
class BlockForm
{
    /**
     * Every type as a Builder block, in the registry's own order.
     *
     * @return array<int, Builder\Block>
     */
    public static function builderBlocks(Site $site): array
    {
        $blocks = [];

        foreach (BlockRegistry::all() as $type => $definition) {
            $blocks[] = Builder\Block::make($type)
                ->label($definition->label())
                ->schema([
                    Toggle::make('is_enabled')
                        ->label('Show this on the page')
                        ->helperText('Off keeps the content but takes the band off the site.')
                        ->default(true),

                    ...self::for($type, $site),
                ]);
        }

        return $blocks;
    }

    /**
     * @return array<int, Component>
     */
    public static function for(string $type, Site $site): array
    {
        return match ($type) {
            'hero' => [
                self::image('image_id', $site, 'Photograph'),
                TextInput::make('eyebrow')->label('Small line above')->maxLength(60),
                // A textarea rather than one line, because this is the only
                // text on the site set at 76px: where it breaks is a decision,
                // and a line break typed here is the one the page uses.
                Textarea::make('headline')->label('The big line')->rows(2)->maxLength(120)
                    ->helperText('Set in the largest type on the page. Press Enter to break the line '
                        .'where you want it broken.'),
                Textarea::make('subline')->label('The line under it')->rows(2)->maxLength(240),
                TextInput::make('cta_label')->label('Button')->maxLength(40),
                TextInput::make('cta_href')->label('Button goes to')->maxLength(2048),
            ],

            'highlights' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Repeater::make('items')
                    ->label('Highlights')
                    ->maxItems(8)
                    ->schema([
                        TextInput::make('title')->label('Title')->required()->maxLength(80),
                        TextInput::make('text')->label('A line about it')->maxLength(300),
                    ]),
            ],

            'about' => [
                TextInput::make('eyebrow')->label('Small line above')->maxLength(60),
                TextInput::make('heading')->label('Heading')->maxLength(120),
                self::richText('body', 'The text'),
                self::image('image_id', $site, 'Photograph beside it'),
                Toggle::make('slideshow')
                    ->label('Show as story slideshow')
                    ->helperText('Long texts (2+ paragraphs) are shown one paragraph at a time. '
                        .'A "Read the full story" link leads to the complete page.')
                    ->default(true),
            ],

            'gallery' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                self::images('image_ids', $site),
            ],

            'opening_hours' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Repeater::make('days')
                    ->label('Hours')
                    ->maxItems(8)
                    ->schema([
                        TextInput::make('day')->label('Day')->required()->maxLength(40),
                        TextInput::make('hours')->label('Open')->required()->maxLength(60)
                            ->placeholder('08:00 – 17:00, or Closed'),
                    ]),
                Textarea::make('note')->label('Anything to add')->rows(2)->maxLength(300),
            ],

            'price_list' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Repeater::make('items')
                    ->label('Lines')
                    ->maxItems(60)
                    ->schema([
                        TextInput::make('name')->label('What it is')->required()->maxLength(120),
                        TextInput::make('description')->label('Description')->maxLength(300),
                        // A string, not a number: a price list says "from N$ 950"
                        // and "on request" as often as it says a figure.
                        TextInput::make('price')->label('Price')->maxLength(40),
                    ]),
                Textarea::make('note')->label('Anything to add')->rows(2)->maxLength(300),
                Toggle::make('whatsapp_order')
                    ->label('Let customers order via WhatsApp')
                    ->helperText('Adds checkboxes to each line. Clicking the button opens WhatsApp with the selected items pre-filled. Requires a WhatsApp number on the site.'),
            ],

            'booking' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('intro')->label('A line above the dates')->rows(2)->maxLength(300),
            ],

            'enquiry' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('intro')->label('A line above the form')->rows(2)->maxLength(300),
                Select::make('mode')
                    ->label('What the form asks for')
                    ->options([
                        EnquiryBlock::MODE_STAY => 'A stay — arrival and departure',
                        EnquiryBlock::MODE_VISIT => 'A visit — a date and a time',
                    ])
                    ->native(false),
            ],

            'rich_text' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                self::richText('body', 'The text'),
            ],

            'mission' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Our Mission'),
                self::richText('body', 'The text'),
            ],

            'why_choose_us' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Why Choose Us?'),
                self::richText('body', 'The text'),
            ],

            'shop' => [
                TextInput::make('heading')->label('Section heading')->maxLength(120)
                    ->placeholder('Shop')
                    ->helperText('Products are managed separately in the shop admin.'),
            ],

            'location' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('directions_note')
                    ->label('Getting there')
                    ->helperText('The last few kilometres, the turn-off, whether it needs a 4x4.')
                    ->rows(3)
                    ->maxLength(600),
            ],

            'contact' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(300),
                Textarea::make('opening_hours')->label('Opening hours')->rows(3)->maxLength(600)
                    ->helperText('Free text — one line per row, e.g. "Monday – Friday: 08:00 – 17:00".'),
                Toggle::make('show_form')->label('Show the message form too'),
            ],

            'cta' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('text')->label('The text')->rows(2)->maxLength(300),
                TextInput::make('label')->label('Button')->maxLength(40),
                TextInput::make('href')->label('Button goes to')->maxLength(2048),
            ],

            'footer' => [
                TextInput::make('legal_name')->label('Registered name')->maxLength(160),
                TextInput::make('registration')->label('Registration number')->maxLength(120),
                TextInput::make('responsible_person')->label('Responsible person')->maxLength(160),
                Textarea::make('note')->label('Anything to add')->rows(2)->maxLength(600),
                Repeater::make('links')
                    ->label('Links')
                    ->maxItems(6)
                    ->schema([
                        TextInput::make('label')->label('Label')->required()->maxLength(60),
                        TextInput::make('href')->label('Address')->required()->maxLength(2048),
                    ]),
            ],

            default => [],
        };
    }

    /**
     * The pictures a site may use are its own, never the listing's — that
     * independence is what makes "you keep your content" true rather than a
     * slogan, and it is why these are ids into site_images.
     */
    private static function image(string $name, Site $site, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            // Lazily: the modal is built for every row of a table, and a
            // query per picture list per row is a page that crawls.
            ->options(fn (): array => self::imageOptions($site))
            ->searchable()
            ->native(false)
            ->placeholder('No picture');
    }

    private static function images(string $name, Site $site): CheckboxList
    {
        return CheckboxList::make($name)
            ->label('Pictures')
            ->options(fn (): array => self::imageOptions($site))
            ->columns(2)
            ->bulkToggleable();
    }

    /**
     * @return array<int, string>
     */
    private static function imageOptions(Site $site): array
    {
        return $site->images()
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (SiteImage $image): array => [
                $image->id => $image->alt ?: basename((string) $image->key),
            ])
            ->all();
    }

    /**
     * Only for keys a definition names in richTextFields(). Whatever comes out
     * of here is purified by SiteBlock before it is written, so the allow-list
     * and not this editor is what decides what a page can contain.
     */
    private static function richText(string $name, string $label): RichEditor
    {
        return RichEditor::make($name)
            ->label($label)
            ->maxLength(8000)
            // No file attachments: an upload here would land outside the site's
            // own media prefix, and a picture belonging to a page is a picture
            // the gallery and the about block already reference by id.
            ->disableToolbarButtons(['attachFiles'])
            ->columnSpanFull();
    }
}
