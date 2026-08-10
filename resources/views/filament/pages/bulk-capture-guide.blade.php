<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">The short version</x-slot>

        <ol class="list-decimal space-y-3 ps-5 text-sm text-gray-700 dark:text-gray-300">
            <li>
                <strong>Get a workbook.</strong> On <em>Content → Listings</em>, use
                <em>Export to Excel</em> for the listings you want to work on (filter the table first —
                the export follows the filters), or <em>Empty template</em> to capture businesses that
                aren't in the system yet.
            </li>
            <li>
                <strong>Fill it in.</strong> The workbook has three sheets: <em>Listings</em>,
                <em>RoomTypes</em>, and <em>Instructions</em> with the same explanations you see below.
            </li>
            <li>
                <strong>Collect the photos</strong> (optional). One folder per listing, all folders in a
                single ZIP file.
            </li>
            <li>
                <strong>Import.</strong> On <em>Content → Import listings</em>, upload the workbook and
                the ZIP, press <em>Check file</em>, read what it says it will do, then press
                <em>Import</em>.
            </li>
        </ol>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">The three rules that matter</x-slot>

        <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <p>
                <strong class="text-gray-950 dark:text-white">The id column decides what happens.</strong>
                A row with an <code>id</code> updates that listing. A row without one creates a new
                listing. Never type an id in yourself and never change one — if a name you type already
                exists, the check will tell you which id to use.
            </p>
            <p>
                <strong class="text-gray-950 dark:text-white">An empty cell changes nothing.</strong>
                Leaving a cell blank means "keep what's there", so you can send a file with only the
                columns you actually researched. To empty a field on purpose, write
                @foreach ($this->clearMarkers() as $marker)<code>{{ $marker }}</code>@if (! $loop->last) or @endif @endforeach
                in the cell.
            </p>
            <p>
                <strong class="text-gray-950 dark:text-white">Nothing is saved before you see it.</strong>
                <em>Check file</em> lists every single change — old value next to new value — and saves
                nothing. If any row has an error, the whole import stops so you can fix the file and try
                again. Nothing is ever deleted by an import.
            </p>
        </div>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">Listings sheet — the columns</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="w-48 py-2 text-start font-medium">Column</th>
                        <th class="py-2 text-start font-medium">What to put in it</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->listingColumns() as $column)
                        <tr>
                            <td class="py-2 align-top font-mono text-xs">
                                {{ $column['header'] }}
                                @if ($column['required'])
                                    <span class="ms-1 text-danger-600 dark:text-danger-400">required</span>
                                @endif
                            </td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $column['help'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            "Required" means required when you create a listing. When you update one, only fill in what
            changes.
        </p>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">RoomTypes sheet — bookable units</x-slot>

        <p class="mb-4 text-sm text-gray-700 dark:text-gray-300">
            One row per room type of an accommodation — a lodge with three kinds of chalet gets three
            rows. This is what lets a traveller pick a room when they book, so an accommodation without
            room types shows "the partner will confirm the room" instead of real availability.
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="w-48 py-2 text-start font-medium">Column</th>
                        <th class="py-2 text-start font-medium">What to put in it</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($this->roomColumns() as $column)
                        <tr>
                            <td class="py-2 align-top font-mono text-xs">
                                {{ $column['header'] }}
                                @if ($column['required'])
                                    <span class="ms-1 text-danger-600 dark:text-danger-400">required</span>
                                @endif
                            </td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $column['help'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            Room rows are matched on <code>listing_id</code> + <code>code</code>, so re-importing the
            same file updates the same rooms instead of duplicating them. A room that is no longer sold
            gets <code>active = no</code> — don't delete the row.
        </p>
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">Photos</x-slot>

        <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <p>Build one folder per listing and zip them all together:</p>

            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs dark:bg-white/5">photos.zip
├── Okonjima Bush Camp/
│   ├── cover.jpg      ← becomes the main image
│   ├── room-1.jpg
│   └── pool.jpg
└── Desert Whisper/
    ├── cover.jpg
    └── dunes.jpg</pre>

            <p>
                In the workbook, write the folder name in the <code>photo_folder</code> column of that
                listing's row — that is the whole connection. A file whose name starts with
                <em>cover</em> becomes the main image; everything else becomes the gallery in name
                order, so numbering them (01, 02, 03…) puts them in the order you want.
            </p>
            <p>
                <strong class="text-gray-950 dark:text-white">Filling in photo_folder replaces</strong>
                the photos that listing already has — it's all of them or none. Leave the cell empty to
                keep the existing photos.
            </p>
            <p class="text-gray-500 dark:text-gray-400">
                {{ $this->photoLimits()['formats'] }} only · at most
                {{ $this->photoLimits()['per_listing'] }} images per listing · at most
                {{ $this->photoLimits()['per_file'] }} per image.
            </p>
            <p>
                Only upload photos we are allowed to publish — the partner's own material, or ours.
                Photos taken from a directory site or a search engine are somebody else's property, no
                matter how good they look. If a credit line is required, put it in
                <code>photo_credit</code>.
            </p>
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Coordinates without the tedium</x-slot>

        <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Leave <code>coordinates</code> empty and fill in <code>address</code> instead. After the
                import, the system looks the address up automatically and fills in what it can find.
            </p>
            <p>
                For the ones it can't find, export the leftovers, then for each row search the place in
                Google Maps, right-click the pin, click the coordinates to copy them, and paste them
                into the cell. Pasting the whole Maps link from the address bar works too — both of
                these are read correctly:
            </p>
            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs dark:bg-white/5">-22.482100, 17.095400
https://www.google.com/maps/@-22.4821,17.0954,15z</pre>
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">When something goes wrong</x-slot>

        <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
            <p>
                Every problem is reported with the row number it happened in, and nothing is saved until
                every row is clean. <em>Download report</em> gives you the same list as a spreadsheet,
                which is the easiest thing to send back to whoever filled the file in.
            </p>
            <p>
                Common ones: a place name that isn't in our list (the valid ones are on the
                <em>Instructions</em> sheet), a listing name that already exists (use its id), or a
                <code>photo_folder</code> that doesn't match a folder in the ZIP — check for a typo or a
                trailing space.
            </p>
            <p>
                New listings are created <strong class="text-gray-950 dark:text-white">not published</strong>
                unless the <code>published</code> column says yes, so a half-finished import never
                reaches the website. You can publish them later from the listings table.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
