<?php

namespace App\Filament\Support\Sites;

use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteImage;
use App\Sites\BlockRegistry;
use App\Sites\Blocks\BlockDefinition;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use App\Sites\Blocks\FaqBlock;
use App\Sites\Blocks\GalleryBlock;
use App\Sites\Blocks\ItineraryBlock;
use App\Sites\Blocks\OffersBlock;
use App\Sites\Blocks\RouteMapBlock;
use App\Sites\Blocks\StatsBlock;
use App\Sites\Blocks\TeamBlock;
use App\Sites\Blocks\TestimonialsBlock;
use App\Sites\Blocks\VideoBlock;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;

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
     * Each is capped at one. A page carries one band of each type — a block row
     * is found by `firstOrNew(['type' => $type])` and `EditBlocksAction::write()`
     * refuses a page with two of anything — and `maxItems(1)` is how that rule
     * reaches the person choosing. Filament drops a block from the picker once
     * the page holds its maximum (`Builder::getBlockPickerBlocks()`), so what is
     * offered is what can actually be added.
     *
     * Without it the picker listed all eighteen types no matter what the page
     * already had, so on a page with nine bands half the menu was a trap: pick
     * one that is already there, and the only thing that says so is a red
     * refusal at save time, after the work of filling it in.
     *
     * @return array<int, Builder\Block>
     */
    public static function builderBlocks(Site $site): array
    {
        $blocks = [];

        foreach (BlockRegistry::all() as $type => $definition) {
            $blocks[] = Builder\Block::make($type)
                ->label($definition->label())
                ->maxItems(1)
                ->schema([
                    Toggle::make('is_enabled')
                        ->label('Show this on the page')
                        ->helperText('Off keeps the content but takes the band off the site.')
                        ->default(true),

                    ...self::for($type, $site),

                    ...($definition->isSection() ? self::navControls($definition) : []),
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
                FileUpload::make('video_key')
                    ->label('Video behind it (optional)')
                    ->disk('r2')
                    ->directory(fn (): string => $site->mediaPrefix().'/videos')
                    ->acceptedFileTypes(['video/mp4', 'video/webm'])
                    ->maxSize(12 * 1024)
                    ->fetchFileInformation(false)
                    ->helperText('A short silent loop, 10-20 seconds, 720p. The photograph is shown until it plays, '
                        .'and instead of it on a phone that saves data.'),
                self::image('scene_side_id', $site, 'Cut-out beside the video (enterprise)')
                    ->helperText('A transparent PNG of people, standing to the left of the video card on a computer.'),
                self::image('scene_front_id', $site, 'Cut-out in front of the video (enterprise)')
                    ->helperText('A transparent PNG of a vehicle, parked in front of the video card.'),
                TextInput::make('video_caption')->label('Line under the video')->maxLength(60)
                    ->placeholder('Filmed on a game drive'),
                Select::make('video_layout')
                    ->label('Video shape')
                    ->options(['cover' => 'Landscape - fills the opening', 'card' => 'Portrait (phone clip) - full screen on a phone, a card beside the headline on a computer'])
                    ->placeholder('Landscape')
                    ->native(false),
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
                self::imageSide(),
                Toggle::make('slideshow')
                    ->label('Show as story slideshow')
                    ->helperText('A long text is shown a paragraph at a time, with arrows to page '
                        .'through it. A text written as one long block is split at sentence '
                        .'boundaries. A "Read the full story" link leads to the complete page.')
                    ->default(true),
            ],

            'offers' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Tours & safaris, Our services, Packages…'),
                Textarea::make('intro')->label('A line above the cards')->rows(2)->maxLength(400),
                TextInput::make('button_label')->label('Button on each card')->maxLength(24)
                    ->placeholder('Enquire')
                    ->helperText('Every card leads to the contact form, with the card’s title already in the message.'),
                Repeater::make('items')
                    ->label('Cards')
                    ->maxItems(OffersBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->label('Title')->required()->maxLength(100)->columnSpanFull(),
                        TextInput::make('duration')->label('How long')->maxLength(40)->placeholder('Full day, 3 days…'),
                        TextInput::make('price')->label('Price')->maxLength(40)->placeholder('from N$ 1 450 pp'),
                        Textarea::make('text')->label('What it is')->rows(3)->maxLength(700)->columnSpanFull(),
                        self::image('image_id', $site, 'Photograph')->columnSpanFull(),
                        Select::make('listing_slug')
                            ->label('Listing on NamibWay')
                            ->options(fn (): array => self::partnerListingOptions($site))
                            ->searchable()
                            ->native(false)
                            ->placeholder('None')
                            ->helperText('With a tour request form, this card opens the form with this tour chosen.')
                            ->columnSpanFull(),
                        TextInput::make('page_slug')
                            ->label('Page with the details')
                            ->maxLength(120)
                            ->placeholder('tours/namibia-safari')
                            ->helperText('The address of a page of this site. The card then links to it.')
                            ->columnSpanFull(),
                    ]),
                TextInput::make('page_button_label')->label('Button to the page')->maxLength(24)->placeholder('Details'),
            ],

            'itinerary' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('The route, Your 14 days…'),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(400),
                Repeater::make('items')
                    ->label('Stops')
                    ->maxItems(ItineraryBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => trim(($state['day'] ?? '').' '.($state['title'] ?? '')) ?: null)
                    ->columns(2)
                    ->schema([
                        TextInput::make('day')->label('Day')->maxLength(24)->placeholder('Day 1, Days 3–4'),
                        TextInput::make('title')->label('Where')->required()->maxLength(100),
                        TextInput::make('drive')->label('The drive')->maxLength(60)->placeholder('300 km · 3½–4 hours')->columnSpanFull(),
                        Textarea::make('text')->label('What happens')->rows(3)->maxLength(600)->columnSpanFull(),
                        TextInput::make('stay')->label('Where you sleep')->maxLength(200)->columnSpanFull(),
                    ]),
                Textarea::make('note')->label('Anything to add')->rows(2)->maxLength(300),
            ],

            'stats' => [
                Repeater::make('items')
                    ->label('Numbers')
                    ->maxItems(StatsBlock::MAX_ITEMS)
                    ->columns(3)
                    ->schema([
                        TextInput::make('value')->label('Number')->required()->integer()->minValue(0)->maxValue(999999),
                        TextInput::make('unit')->label('After it')->maxLength(12)->placeholder('days, +, %'),
                        TextInput::make('label')->label('What it counts')->required()->maxLength(60),
                    ]),
                TagsInput::make('ticker')
                    ->label('Running line')
                    ->helperText('Place names or words that scroll past under the numbers. Up to '.StatsBlock::MAX_TICKER.'.'),
            ],

            'route_map' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)->placeholder('Where the road goes'),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(400),
                TextInput::make('title')->label('Title on the map')->maxLength(60)->placeholder('The Grand Namibia Safari'),
                TextInput::make('country')->label('Country (ISO code)')->maxLength(2)->default('NA'),
                Repeater::make('items')
                    ->label('Stops, in order')
                    ->maxItems(RouteMapBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->columns(4)
                    ->schema([
                        TextInput::make('name')->label('Place')->required()->maxLength(40),
                        TextInput::make('label')->label('Day')->maxLength(24)->placeholder('Days 2-3'),
                        TextInput::make('lat')->label('Latitude')->required()->numeric(),
                        TextInput::make('lng')->label('Longitude')->required()->numeric(),
                    ]),
                TextInput::make('note')->label('Small print')->maxLength(200),
            ],

            'photo_band' => [
                self::image('image_id', $site, 'Photograph')
                    ->helperText('Shown across the whole screen — pick a wide picture with space in it.'),
                TextInput::make('statement')->label('The line over it')->maxLength(140),
                TextInput::make('caption')->label('Small line under it')->maxLength(80),
            ],

            'gallery' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                self::images('image_ids', $site)
                    ->helperText('The first '.GalleryBlock::VISIBLE.' are shown, the rest behind “Show all”. Up to '
                        .GalleryBlock::MAX_IMAGES.'.'),
            ],

            'video' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(400),
                Repeater::make('items')
                    ->label('Videos')
                    ->maxItems(VideoBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['caption'] ?? null)
                    ->schema([
                        FileUpload::make('key')
                            ->label('Video')
                            ->disk('r2')
                            ->directory(fn (): string => $site->mediaPrefix().'/videos')
                            ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime'])
                            // Livewire's own upload limit; a phone clip of
                            // a minute is well under it.
                            ->maxSize(12 * 1024)
                            ->fetchFileInformation(false)
                            ->helperText('MP4 from a phone is fine. Up to 12 MB — keep clips short.')
                            ->required(),
                        self::image('poster_image_id', $site, 'Picture shown before it plays')
                            ->helperText('Optional. With one, nothing of the video loads until somebody taps play.'),
                        TextInput::make('caption')->label('Caption')->maxLength(140),
                    ]),
            ],

            'team' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Meet your guide, The team…'),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(400),
                Repeater::make('items')
                    ->label('People')
                    ->maxItems(TeamBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Name')->required()->maxLength(80),
                        TextInput::make('role')->label('Role')->maxLength(80)->placeholder('Guide · English, German, Oshiwambo'),
                        Textarea::make('text')->label('About them')->rows(3)->maxLength(900)->columnSpanFull(),
                        self::image('image_id', $site, 'Portrait')->columnSpanFull(),
                    ]),
            ],

            'testimonials' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('What guests say'),
                Repeater::make('items')
                    ->label('Quotes')
                    ->helperText('Only words a guest really wrote — a review, a guest book, an email. Never written for them.')
                    ->maxItems(TestimonialsBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->columns(2)
                    ->schema([
                        Textarea::make('quote')->label('What they said')->required()->rows(3)->maxLength(600)->columnSpanFull(),
                        TextInput::make('name')->label('Name')->required()->maxLength(80),
                        TextInput::make('origin')->label('From / what they did')->maxLength(80)
                            ->placeholder('Germany · Etosha day trip'),
                        Toggle::make('sample')->label('Sample quote (placeholder)')
                            ->helperText('Shown with a “Sample” tag, and the site cannot be published while one is here.')
                            ->columnSpanFull(),
                    ]),
            ],

            'faq' => [
                self::image('background_image_id', $site, 'Photograph behind it (optional)')
                    ->helperText('Shown very faintly behind this band, so a page does not end on a flat colour.'),
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Repeater::make('items')
                    ->label('Questions')
                    ->maxItems(FaqBlock::MAX_ITEMS)
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                    ->schema([
                        TextInput::make('question')->label('Question')->required()->maxLength(160),
                        Textarea::make('answer')->label('Answer')->required()->rows(3)->maxLength(1200),
                    ]),
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
                self::image('background_image_id', $site, 'Photograph behind it (optional)')
                    ->helperText('Shown very faintly behind this band, so a page does not end on a flat colour.'),
                // One type, not a set of toggles. A page offering a table
                // booking and a product order and a general contact form has
                // not decided what it sells.
                Select::make('form_type')
                    ->label('What the form is for')
                    ->options(EnquiryFormType::options())
                    ->default(EnquiryFormType::StayRequest->value)
                    ->native(false)
                    ->live()
                    // What this site can actually render, checked against the
                    // business behind it rather than described in general. The
                    // page degrades quietly to a contact form when the pick
                    // cannot be honoured, which is right in front of a visitor
                    // and useless here — so the reason is said where the choice
                    // is made, and it names the thing that is missing.
                    ->helperText(fn (Get $get): string => EnquiryBlock::unavailableReason(
                        $site,
                        EnquiryFormType::tryFrom((string) $get('form_type')) ?? EnquiryFormType::StayRequest,
                    ) ?? 'One form per site — pick the one that matches what you are selling.'),
                Select::make('channel')
                    ->label('How it is answered')
                    ->options([
                        EnquiryBlock::CHANNEL_EMAIL => 'By email — the form is sent to you',
                        EnquiryBlock::CHANNEL_WHATSAPP => 'By WhatsApp — the form opens a message',
                    ])
                    ->default(EnquiryBlock::CHANNEL_EMAIL)
                    ->native(false)
                    ->helperText('One or the other. Asking a visitor to choose a medium before they have said anything costs you the message.'),
                TextInput::make('heading')
                    ->label('Heading')
                    ->maxLength(120)
                    ->helperText('One of the standard headings follows the form type. Write your own and it stays exactly as typed.')
                    ->placeholder(fn (Get $get): string => EnquiryFormType::tryFrom((string) $get('form_type'))?->heading()
                        ?? EnquiryFormType::StayRequest->heading()),
                TextInput::make('button_label')
                    ->label('Menu button')
                    ->maxLength(24)
                    ->helperText('What the button in the menu bar says. Short — it lands in a 96px button on a phone.')
                    ->placeholder(fn (Get $get): string => EnquiryFormType::tryFrom((string) $get('form_type'))?->buttonLabel()
                        ?? EnquiryFormType::StayRequest->buttonLabel()),
                Textarea::make('intro')->label('A line above the form')->rows(2)->maxLength(300),
            ],

            'rich_text' => [
                TextInput::make('heading')->label('Heading')->maxLength(120),
                self::richText('body', 'The text'),
            ],

            'mission' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Our Mission'),
                self::richText('body', 'The text'),
                self::image('image_id', $site, 'Photograph beside it'),
                self::imageSide(),
            ],

            'why_choose_us' => [
                TextInput::make('heading')->label('Heading')->maxLength(120)
                    ->placeholder('Why Choose Us?'),
                self::richText('body', 'The text'),
                self::image('image_id', $site, 'Photograph beside it'),
                self::imageSide(),
            ],

            'shop' => [
                TextInput::make('heading')->label('Section heading')->maxLength(120)
                    ->placeholder('Shop'),
                TextInput::make('product_count')
                    ->label('Products shown on homepage')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(60)
                    ->default(8)
                    ->placeholder('8')
                    ->helperText('A "View all" button appears automatically when there are more.'),
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
                self::image('background_image_id', $site, 'Photograph behind it (optional)')
                    ->helperText('Shown very faintly behind this band, so a page does not end on a flat colour.'),
                TextInput::make('heading')->label('Heading')->maxLength(120),
                Textarea::make('intro')->label('A line above it')->rows(2)->maxLength(300),
                Textarea::make('opening_hours')->label('Opening hours')->rows(3)->maxLength(600)
                    ->helperText('Free text — one line per row, e.g. "Monday – Friday: 08:00 – 17:00".'),
                Toggle::make('show_form')->label('Show the message form too'),
            ],

            'cta' => [
                self::image('background_image_id', $site, 'Photograph behind it (optional)')
                    ->helperText('Shown very faintly behind this band, so a page does not end on a flat colour.'),
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
     * @return array<int, Component>
     */
    private static function navControls(BlockDefinition $definition): array
    {
        return [
            Toggle::make('nav_visible')
                ->label('Show in the menu')
                ->helperText('Adds this section as a link in the site navigation bar.')
                ->default($definition->navDefault())
                ->live(),
            TextInput::make('nav_label')
                ->label('Menu label')
                ->maxLength(40)
                ->helperText('Leave empty to use the heading. Keep it short — it lands in a small bar.')
                ->visible(fn (Get $get): bool => (bool) $get('nav_visible')),
        ];
    }

    private static function imageSide(): Select
    {
        return Select::make('image_side')
            ->label('Image side')
            ->options(['right' => 'Right', 'left' => 'Left'])
            ->default('right')
            ->native(false)
            ->helperText('Which side the photograph sits on beside the text.');
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
            ->allowHtml()
            ->searchable()
            ->native(false)
            ->placeholder('No picture');
    }

    private static function images(string $name, Site $site): CheckboxList
    {
        return CheckboxList::make($name)
            ->label('Pictures')
            ->options(fn (): array => self::imageOptions($site))
            ->allowHtml()
            ->columns(2)
            ->bulkToggleable();
    }

    /**
     * Each option renders a small thumbnail beside the alt text and filename so
     * the picker is a gallery, not a list of random R2 keys. HTML is safe here:
     * both alt and filename are escaped before being placed in the markup.
     *
     * @return array<int, string>
     */
    private static function imageOptions(Site $site): array
    {
        return $site->images()
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(function (SiteImage $image): array {
                $thumb = e($image->thumb(80));
                $filename = e(basename((string) $image->key));
                $alt = filled($image->alt) ? e($image->alt) : '';

                $label = '<span style="display:flex;align-items:center;gap:10px;padding:2px 0">'
                    .'<img src="'.$thumb.'" width="40" height="40" loading="lazy"'
                    .' style="flex:none;width:40px;height:40px;object-fit:cover;border-radius:3px">'
                    .'<span style="min-width:0;overflow:hidden">'
                    .($alt !== '' ? '<span style="display:block;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'.$alt.'</span>' : '')
                    .'<span style="display:block;font-size:11px;opacity:.55;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'.$filename.'</span>'
                    .'</span>'
                    .'</span>';

                return [$image->id => $label];
            })
            ->all();
    }

    /**
     * The business's own listings, by slug — what an offer card can point at.
     *
     * @return array<string, string>
     */
    private static function partnerListingOptions(Site $site): array
    {
        if ($site->partner_id === null) {
            return [];
        }

        return Listing::where('partner_id', $site->partner_id)
            ->orderBy('slug')
            ->get(['slug', 'name'])
            ->mapWithKeys(fn (Listing $listing): array => [(string) $listing->slug => (string) $listing->name])
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
