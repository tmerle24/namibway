<x-filament-panels::page>
    <div class="nwbc">
        <style>
            .nwbc {
                --ink: #2A2317; --ink-soft: #6B6350; --line: #E4D9BE; --paper-2: #F5F0E3;
                --accent: #2C3E5C; --accent-soft: #DCE2EA; --gold: #B9812E;
                --good: #3F6B4A; --good-bg: #E4EBDF; --flag: #A23E28; --flag-bg: #F2E2D9;
                color: var(--ink);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
                line-height: 1.55;
                max-width: 62rem;
            }
            .dark .nwbc {
                --ink: #EDE6D6; --ink-soft: #B3A98E; --line: #3B3526; --paper-2: #242019;
                --accent: #8FA6CC; --accent-soft: #2A3345; --gold: #D9A54F;
                --good: #8FBF9B; --good-bg: #243527; --flag: #E08A6F; --flag-bg: #3A2620;
            }
            .nwbc h1, .nwbc h2, .nwbc h3 {
                font-family: Georgia, "Iowan Old Style", "Times New Roman", serif;
                font-weight: 600; text-wrap: balance; color: var(--ink); margin: 0;
            }
            .nwbc .eyebrow {
                font-size: 12px; letter-spacing: .11em; text-transform: uppercase;
                color: var(--gold); font-weight: 700; margin: 0 0 8px;
            }
            .nwbc h1 { font-size: 30px; line-height: 1.15; margin-bottom: 6px; }
            .nwbc .subtitle { color: var(--ink-soft); font-size: 15.5px; margin: 0 0 28px; max-width: 62ch; }
            .nwbc h2 { font-size: 20px; }
            .nwbc h3 { font-size: 15px; margin: 18px 0 6px; color: var(--accent); }
            .nwbc .section-note { color: var(--ink-soft); font-size: 14px; margin: 4px 0 16px; max-width: 66ch; }
            .nwbc p { margin: 0 0 12px; max-width: 70ch; }
            .nwbc p:last-child { margin-bottom: 0; }
            .nwbc a { color: var(--accent); }

            .nwbc section { padding: 28px 0; border-top: 1px solid var(--line); }
            .nwbc section:first-of-type { border-top: none; padding-top: 0; }

            .nwbc code {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12.5px; color: var(--accent);
                background: var(--accent-soft); border-radius: 5px; padding: 1px 6px;
            }
            .nwbc pre {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12.5px; line-height: 1.65; color: var(--ink);
                background: var(--paper-2); border: 1px solid var(--line); border-radius: 10px;
                padding: 14px 16px; margin: 14px 0; overflow-x: auto;
            }
            .nwbc pre .note { color: var(--ink-soft); }

            .nwbc ol.steps { list-style: none; margin: 0; padding: 0; }
            .nwbc ol.steps li { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px dashed var(--line); }
            .nwbc ol.steps li:last-child { border-bottom: none; }
            .nwbc ol.steps li .n { font-family: Georgia, serif; font-weight: 700; color: var(--gold); flex-shrink: 0; width: 18px; }

            .nwbc .rules { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
            .nwbc .rules > div {
                background: var(--paper-2); border: 1px solid var(--line); border-radius: 12px;
                padding: 16px 16px 18px;
            }
            .nwbc .rules dt { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--gold); font-weight: 700; margin: 0 0 6px; }
            .nwbc .rules dd { margin: 0; font-size: 14px; color: var(--ink); }

            .nwbc table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 14px; }
            .nwbc th, .nwbc td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
            .nwbc th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-soft); font-weight: 700; }
            .nwbc td.col { white-space: nowrap; }
            .nwbc .table-wrap { overflow-x: auto; }

            .nwbc .tag { display: inline-block; font-size: 10.5px; font-weight: 700; letter-spacing: .03em; padding: 2px 8px; border-radius: 100px; }
            .nwbc .tag.required { background: var(--flag-bg); color: var(--flag); }

            .nwbc .callout {
                border-left: 3px solid var(--flag); background: var(--flag-bg);
                border-radius: 0 8px 8px 0; padding: 12px 16px; margin: 14px 0; font-size: 14px;
            }
            .nwbc .callout strong { color: var(--flag); }
            .nwbc .callout.good { border-left-color: var(--good); background: var(--good-bg); }
            .nwbc .callout.good strong { color: var(--good); }

            @media (max-width: 900px) {
                .nwbc .rules { grid-template-columns: 1fr; }
            }
        </style>

        <p class="eyebrow">Documentation</p>
        <h1>Capturing listings in Excel</h1>
        <p class="subtitle">
            Export what exists, edit it in a spreadsheet, import it back — with photos and room types.
            Faster than the forms for anything beyond a handful of listings, and safe to repeat: an
            import shows you every change before it saves a thing.
        </p>

        <section>
            <h2>The short version</h2>
            <ol class="steps">
                <li>
                    <span class="n">1</span>
                    <span>
                        <strong>Get a workbook.</strong> On <em>Content&nbsp;→&nbsp;Listings</em>, press
                        <em>Export to Excel</em> for the listings you want to work on — filter the table
                        first, the export follows the filters — or <em>Empty template</em> for businesses
                        that aren't in the system yet.
                    </span>
                </li>
                <li>
                    <span class="n">2</span>
                    <span>
                        <strong>Fill it in.</strong> Three sheets: <em>Listings</em>, <em>BookableUnits</em>,
                        and <em>Instructions</em> with the same explanations you're reading here.
                    </span>
                </li>
                <li>
                    <span class="n">3</span>
                    <span>
                        <strong>Collect the photos</strong> (optional): one folder per listing, all
                        folders in a single ZIP file.
                    </span>
                </li>
                <li>
                    <span class="n">4</span>
                    <span>
                        <strong>Import.</strong> <em>Import listings</em> on the same screen: upload the
                        workbook and the ZIP, press <em>Check file</em>, read what it says it will do,
                        then press <em>Import</em>.
                    </span>
                </li>
            </ol>
        </section>

        <section>
            <h2>Three rules that explain everything else</h2>
            <p class="section-note">
                Learn these and the rest of this page is just detail.
            </p>

            <dl class="rules">
                <div>
                    <dt>The id decides</dt>
                    <dd>
                        A row with an <code>id</code> updates that listing; a row without one creates a
                        new listing. Never type an id yourself and never change one. If a name you type
                        already exists, the check tells you which id to use.
                    </dd>
                </div>
                <div>
                    <dt>Empty means untouched</dt>
                    <dd>
                        A blank cell keeps what's already there, so you can send a file with only the
                        columns you actually researched. To empty a field on purpose, write
                        @foreach ($this->clearMarkers() as $marker)<code>{{ $marker }}</code>@if (! $loop->last) or @endif @endforeach
                        in the cell.
                    </dd>
                </div>
                <div>
                    <dt>Nothing saves unseen</dt>
                    <dd>
                        <em>Check file</em> lists every change, old value beside new, and saves nothing.
                        One bad row stops the whole import so you can fix the file. Nothing is ever
                        deleted by an import.
                    </dd>
                </div>
            </dl>
        </section>

        <section>
            <h2>Listings sheet</h2>
            <p class="section-note">
                One row per business. <span class="tag required">required</span> means required when you
                create a listing — when you update one, fill in only what changes.
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>What to put in it</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->listingColumns() as $column)
                            <tr>
                                <td class="col">
                                    <code>{{ $column['header'] }}</code>
                                    @if ($column['required'])
                                        <span class="tag required">required</span>
                                    @endif
                                </td>
                                <td>{{ $column['help'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <h2>BookableUnits sheet</h2>
            <p class="section-note">
                One row per room type of an accommodation — a lodge with three kinds of chalet gets three
                rows. This is what lets a traveller pick a room when they book; without room types the
                booking screen falls back to “the partner will confirm the room”.
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>What to put in it</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->roomColumns() as $column)
                            <tr>
                                <td class="col">
                                    <code>{{ $column['header'] }}</code>
                                    @if ($column['required'])
                                        <span class="tag required">required</span>
                                    @endif
                                </td>
                                <td>{{ $column['help'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="callout good">
                <strong>Rooms are matched on listing + code.</strong> Re-importing the same file updates
                the same rooms instead of duplicating them, and a room row may name a listing this very
                import is creating — so a new lodge and its chalets fit in one file. A room that is no
                longer sold gets <code>active = no</code>; don't delete the row.
            </div>
        </section>

        <section>
            <h2>Photos</h2>
            <p>
                Build one folder per listing, put the room folders inside them, and zip the lot:
            </p>

            <pre>photos.zip
├── Okonjima Bush Camp/
│   ├── cover.jpg          <span class="note">← becomes the main image</span>
│   ├── 01-pool.jpg
│   ├── 02-dining.jpg
│   └── STD/               <span class="note">← a room folder: photo_folder on the BookableUnits sheet</span>
│       ├── 01-bed.jpg
│       └── 02-bath.jpg
└── Desert Whisper/
    ├── cover.jpg
    └── dunes.jpg</pre>

            <p>
                Write the folder name in the <code>photo_folder</code> column of the listing's row — that
                is the whole connection. For a room, write it in <code>photo_folder</code> on the
                BookableUnits sheet. Because every lodge has an “STD”, write enough of the path to be
                unambiguous there: <code>Okonjima Bush Camp/STD</code>. If a name matches more than one
                folder, the check says so and names the candidates.
            </p>

            <h3>What becomes what</h3>
            <p>
                For a listing, a file whose name starts with <em>cover</em> becomes the main image and
                everything else becomes the gallery. For a room, all of them become that room's gallery.
                Either way the order is the file name, so numbering them (01, 02, 03…) puts them in the
                order you want.
            </p>

            <div class="callout">
                <strong>Filling in photo_folder replaces</strong> the photos that listing or room already
                has — all of them or none, never a merge. Leave the cell empty to keep the existing
                photos.
            </div>

            <p class="section-note">
                {{ $this->photoLimits()['formats'] }} only · at most
                {{ $this->photoLimits()['per_listing'] }} images per folder · at most
                {{ $this->photoLimits()['per_file'] }} per image.
            </p>

            <p>
                Only upload photos we are allowed to publish — the partner's own material, or ours.
                Photos taken from a directory site or a search engine belong to somebody else, no matter
                how good they look. If a credit line is required, put it in <code>photo_credit</code>.
            </p>
        </section>

        <section>
            <h2>Coordinates without the tedium</h2>
            <p>
                Leave <code>coordinates</code> empty and fill in <code>address</code> instead. After the
                import, the system looks the address up automatically and fills in what it finds.
            </p>
            <p>
                For the ones it can't find, export the leftovers, search the place in Google Maps,
                right-click the pin and copy the coordinates into the cell. Pasting the whole Maps link
                from the address bar works just as well — both of these are read correctly:
            </p>
            <pre>-22.482100, 17.095400
https://www.google.com/maps/@-22.4821,17.0954,15z</pre>
        </section>

        <section>
            <h2>When something goes wrong</h2>
            <p>
                Every problem is reported with the row number it happened in, and nothing is saved until
                every row is clean. <em>Download report</em> gives you the same list as a spreadsheet,
                which is the easiest thing to send back to whoever filled the file in.
            </p>
            <p>
                The common ones: a place name that isn't in our list (the valid ones are on the
                <em>Instructions</em> sheet), a listing name that already exists (use its id), or a
                <code>photo_folder</code> that doesn't match a folder in the ZIP — check for a typo or a
                trailing space.
            </p>

            <div class="callout good">
                <strong>New listings are created unpublished</strong> unless the <code>published</code>
                column says yes, so a half-finished import never reaches the website. Publish them later
                from the listings table, once you've looked them over.
            </div>
        </section>
    </div>
</x-filament-panels::page>
