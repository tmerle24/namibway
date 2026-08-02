<x-filament-panels::page>
    {{--
        Styling ported 1:1 from the "Booking-Connector Checkliste" artifact
        (same CSS custom properties, class names, card/badge/table structure).
        Two adaptations for embedding inside Filament rather than standing alone:
        - Everything is scoped under .nw-guide instead of :root/body/bare tags,
          so it can't leak into the rest of the admin panel's chrome.
        - Dark mode follows Filament's actual toggle (.dark on <html>), not the
          artifact-runtime's data-theme attribute; prefers-color-scheme stays as
          a fallback for the OS-level default.
    --}}
    <style>
        .nw-guide {
            --paper: #fbf8f1;
            --ink: #241c15;
            --ink-soft: #6b6152;
            --sand: #ede6d6;
            --sand-dark: #ded2b8;
            --rust: #b5651d;
            --rust-dark: #8c4a15;
            --sage: #4c5d46;
            --sage-light: #dce4d6;
            --danger: #a13d2f;
            --danger-light: #f3ddd7;
            --gold: #c98a2e;
            --line: rgba(36, 28, 21, 0.12);
        }
        @media (prefers-color-scheme: dark) {
            .nw-guide {
                --paper: #1a1611; --ink: #f2ece0; --ink-soft: #b3a692;
                --sand: #2b2318; --sand-dark: #3b301f; --rust: #e79552; --rust-dark: #f2b077;
                --sage: #93b389; --sage-light: #263429; --danger: #e2796a; --danger-light: #3c231f;
                --gold: #e3ab5c; --line: rgba(242, 236, 224, 0.12);
            }
        }
        .dark .nw-guide {
            --paper: #1a1611; --ink: #f2ece0; --ink-soft: #b3a692;
            --sand: #2b2318; --sand-dark: #3b301f; --rust: #e79552; --rust-dark: #f2b077;
            --sage: #93b389; --sage-light: #263429; --danger: #e2796a; --danger-light: #3c231f;
            --gold: #e3ab5c; --line: rgba(242, 236, 224, 0.12);
        }

        .nw-guide { color: var(--ink); font-size: 16px; line-height: 1.6; }
        .nw-guide * { box-sizing: border-box; }
        .nw-guide h1, .nw-guide h2, .nw-guide h3 {
            font-family: ui-serif, Georgia, "Iowan Old Style", "Times New Roman", serif;
            font-weight: 600; text-wrap: balance; margin: 0; color: var(--ink);
        }
        .nw-guide code, .nw-guide .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.88em; }
        .nw-guide a { color: var(--rust); }

        .nw-guide .page {
            max-width: 1040px; margin: 0 auto; padding: 8px 0 40px;
            display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 56px; align-items: start;
        }

        .nw-guide .header { grid-column: 1 / -1; max-width: 720px; margin-bottom: 8px; }
        .nw-guide .eyebrow {
            text-transform: uppercase; letter-spacing: 0.14em; font-size: 11.5px; font-weight: 700;
            color: var(--rust-dark); margin-bottom: 10px;
        }
        .dark .nw-guide .eyebrow { color: var(--rust); }
        .nw-guide .header p { color: var(--ink-soft); font-size: 16px; max-width: 62ch; }

        .nw-guide nav.toc { position: sticky; top: 32px; display: flex; flex-direction: column; gap: 2px; }
        .nw-guide nav.toc .navlabel {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--ink-soft); margin-bottom: 8px;
        }
        .nw-guide nav.toc a {
            text-decoration: none; color: var(--ink-soft); font-size: 14px; font-weight: 600;
            padding: 7px 10px; border-radius: 7px; border-left: 2px solid transparent;
        }
        .nw-guide nav.toc a:hover { color: var(--ink); background: var(--sand); }

        .nw-guide main { min-width: 0; display: flex; flex-direction: column; gap: 48px; }

        .nw-guide .workflow { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .nw-guide .step { background: var(--sand); border-radius: 12px; padding: 18px 18px 20px; position: relative; }
        .nw-guide .step .num { font-family: ui-serif, Georgia, serif; font-size: 26px; color: var(--rust); display: block; margin-bottom: 6px; }
        .nw-guide .step h3 { font-size: 15.5px; margin-bottom: 6px; }
        .nw-guide .step p { font-size: 13.5px; color: var(--ink-soft); margin: 0; }

        .nw-guide .overview-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; }
        .nw-guide table.overview { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 640px; }
        .nw-guide table.overview th, .nw-guide table.overview td {
            text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line); vertical-align: top;
        }
        .nw-guide table.overview thead th {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--ink-soft); background: var(--sand);
        }
        .nw-guide table.overview tbody tr:last-child td { border-bottom: none; }
        .nw-guide table.overview td.name { font-weight: 700; }

        .nw-guide .card {
            scroll-margin-top: 28px; border: 1px solid var(--line); border-radius: 14px; padding: 26px 26px 28px;
            background: color-mix(in srgb, var(--sand) 35%, var(--paper));
        }
        .nw-guide .card-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 10px 16px; margin-bottom: 6px; }
        .nw-guide .card-head h2 { font-size: 21px; }
        .nw-guide .badge {
            display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.06em; padding: 4px 10px; border-radius: 99px; white-space: nowrap;
        }
        .nw-guide .badge.live { background: var(--sage-light); color: var(--sage); }
        .nw-guide .badge.content { background: var(--sand-dark); color: var(--ink-soft); }
        .nw-guide .badge.manual { background: var(--danger-light); color: var(--danger); }
        .nw-guide .card-sub { color: var(--ink-soft); font-size: 14px; margin-bottom: 18px; max-width: 66ch; }

        .nw-guide .fields { overflow-x: auto; }
        .nw-guide table.fields-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 560px; }
        .nw-guide table.fields-table th, .nw-guide table.fields-table td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line); }
        .nw-guide table.fields-table thead th { font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.07em; color: var(--ink-soft); }
        .nw-guide table.fields-table tbody tr:last-child td { border-bottom: none; }
        .nw-guide .where { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 5px; background: var(--sand-dark); color: var(--ink-soft); }
        .nw-guide .where.listing { background: var(--rust); color: var(--paper); }
        .nw-guide .req { color: var(--danger); font-weight: 700; }
        .nw-guide .opt { color: var(--ink-soft); }

        .nw-guide .note {
            margin-top: 16px; font-size: 13.5px; background: var(--sand); border-left: 3px solid var(--gold);
            border-radius: 0 8px 8px 0; padding: 12px 16px; color: var(--ink);
        }
        .nw-guide .note strong { color: var(--rust-dark); }
        .dark .nw-guide .note strong { color: var(--rust); }

        .nw-guide footer { grid-column: 1 / -1; margin-top: 8px; padding-top: 24px; border-top: 1px solid var(--line); font-size: 13px; color: var(--ink-soft); }

        @media (max-width: 760px) {
            .nw-guide .page { grid-template-columns: 1fr; gap: 28px; }
            .nw-guide nav.toc { position: static; flex-direction: row; flex-wrap: wrap; gap: 6px; }
            .nw-guide nav.toc .navlabel { display: none; }
            .nw-guide nav.toc a { background: var(--sand); border-left: none; }
            .nw-guide .workflow { grid-template-columns: 1fr; }
        }
    </style>

    <div class="nw-guide">
        <div class="page">
            <header class="header">
                <div class="eyebrow">Filament Admin · Partner &amp; Listings</div>
                <p>Which fields need to be filled in where so a listing gets real availability &amp; reservations
                    via ResConnect, NightsBridge, hopeCloud, NamibWay's own booking system, Wetu, or NWR —
                    partner by partner, listing by listing.</p>
            </header>

            <nav class="toc" aria-label="Connector types">
                <span class="navlabel">On this page</span>
                <a href="#workflow">Workflow</a>
                <a href="#resconnect">ResConnect</a>
                <a href="#nightsbridge">NightsBridge</a>
                <a href="#hopecloud">hopeCloud</a>
                <a href="#native">NamibWay Native</a>
                <a href="#wetu">Wetu</a>
                <a href="#nwr-manual">NWR &amp; Manual</a>
            </nav>

            <main>
                <section id="workflow">
                    <div class="workflow">
                        <div class="step">
                            <span class="num">1</span>
                            <h3>Set up the partner account</h3>
                            <p><em>Partner &rarr; Booking Connector.</em> Choose the connector type, enter API
                                credentials. Applies to all of this partner's listings. Easiest done directly via
                                the wizard on the partner's first listing page.</p>
                        </div>
                        <div class="step">
                            <span class="num">2</span>
                            <h3>Connect the listing</h3>
                            <p><em>Listing &rarr; Booking system / API.</em> Enter this specific listing's property
                                code — a partner can have several properties, each with its own code.</p>
                        </div>
                        <div class="step">
                            <span class="num">3</span>
                            <h3>Test</h3>
                            <p>Click <strong>"Test connector"</strong> in the listings table. Checks
                                <code>checkAvailability()</code> live and reports success or the failure reason —
                                without creating a real reservation.</p>
                        </div>
                    </div>
                </section>

                <section class="overview-wrap" aria-label="Overview">
                    <table class="overview">
                        <thead>
                            <tr><th>Connector</th><th>Partner fields</th><th>Listing field</th><th>Live availability?</th></tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="name">ResConnect</td>
                                <td class="mono">api_key, base_url (opt.)</td>
                                <td class="mono">connector_property_code</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td class="name">NightsBridge</td>
                                <td class="mono">bbid, api_key, base_url (opt.)</td>
                                <td class="mono">connector_property_code</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td class="name">hopeCloud</td>
                                <td class="mono">api_key, account_id, base_url (opt.)</td>
                                <td class="mono">connector_property_code</td>
                                <td>Yes</td>
                            </tr>
                            <tr>
                                <td class="name">NamibWay Native</td>
                                <td>— none —</td>
                                <td class="mono">Room types (repeater)</td>
                                <td>Yes — our own engine</td>
                            </tr>
                            <tr>
                                <td class="name">Wetu</td>
                                <td class="mono">api_key</td>
                                <td class="mono">wetu_id (own field!)</td>
                                <td>No — content only</td>
                            </tr>
                            <tr>
                                <td class="name">NWR / Manual</td>
                                <td>—</td>
                                <td>—</td>
                                <td>No — manual review</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                @php
                    $connectors = [
                        [
                            'id' => 'resconnect',
                            'name' => 'ResConnect (ResRequest)',
                            'badge' => ['label' => '● Live bookable', 'class' => 'live'],
                            'intro' => 'The market leader among Southern African/Namibian lodges. Free API called ResConnect.',
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "ResConnect (ResRequest)"'],
                                ['api_key', 'Partner', true, 'API key from the ResRequest partner portal'],
                                ['base_url', 'Partner', false, 'Only set if different from api.resrequest.com'],
                                ['connector_property_code', 'Listing', true, "This listing's ResRequest property code"],
                            ],
                            'note' => 'api_key and base_url go into the Partner\'s "Connector Config" key/value field.',
                        ],
                        [
                            'id' => 'nightsbridge',
                            'name' => 'NightsBridge',
                            'badge' => ['label' => '● Live bookable', 'class' => 'live'],
                            'intro' => 'Channel manager, common among smaller lodges & guesthouses. Authenticates via Basic Auth with bbid + api_key.',
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "NightsBridge"'],
                                ['bbid', 'Partner', true, 'Booking Bureau ID from NightsBridge'],
                                ['api_key', 'Partner', true, 'API key from NightsBridge'],
                                ['base_url', 'Partner', false, 'Only set if different from the default'],
                                ['connector_property_code', 'Listing', true, 'Enter the same bbid here again'],
                            ],
                            'note' => "NightsBridge ties a bbid to exactly one property — so the listing's property code is the same value as the partner's bbid, not a second code. If a partner manages several properties, each has its own bbid+api_key combination and therefore needs its own partner entry.",
                        ],
                        [
                            'id' => 'hopecloud',
                            'name' => 'hopeCloud',
                            'badge' => ['label' => '● Live bookable', 'class' => 'live'],
                            'intro' => 'Namibia-specific — automatically handles the Tourism Levy and reports for NTB/HAN.',
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "hopeCloud"'],
                                ['api_key', 'Partner', true, 'API key from hopeCloud'],
                                ['account_id', 'Partner', true, 'Account ID from hopeCloud'],
                                ['base_url', 'Partner', false, 'Only set if different from the default'],
                                ['connector_property_code', 'Listing', true, "This listing's hopeCloud property/unit ID"],
                            ],
                            'note' => null,
                        ],
                        [
                            'id' => 'native',
                            'name' => 'NamibWay Native Booking',
                            'badge' => ['label' => '● Live bookable', 'class' => 'live'],
                            'intro' => "NamibWay's own booking engine, for partners with no PMS at all. No external API, no credentials — availability lives entirely in our own database.",
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "NamibWay Native Booking"'],
                                ['Room types', 'Listing', true, 'Add via the "Room types" repeater in this same section — name, code, units, rate per night, max occupancy'],
                            ],
                            'note' => 'Availability is computed live: each room type\'s total units minus overlapping active bookings — no calendar to maintain. A booking is held for 24h (status "On Request") awaiting the partner\'s confirmation via the usual email link, then auto-released if not confirmed in time.',
                        ],
                        [
                            'id' => 'wetu',
                            'name' => 'Wetu',
                            'badge' => ['label' => '○ Content only', 'class' => 'content'],
                            'intro' => 'Not a booking connector but a content connector — imports name, description, highlights, region & coordinates. No availability check.',
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "Wetu (content only)"'],
                                ['api_key', 'Partner', true, 'API key from Wetu'],
                                ['wetu_id', 'Listing', true, "This listing's Wetu property ID"],
                            ],
                            'note' => 'Wetu uses wetu_id directly on the listing — not the "Booking system / API" field connector_property_code. Afterwards, use "Import from Wetu" in the listings table.',
                        ],
                        [
                            'id' => 'nwr-manual',
                            'name' => 'NWR & Manual',
                            'badge' => ['label' => '✕ No API access', 'class' => 'manual'],
                            'intro' => 'Namibia Wildlife Resorts (Etosha, Sossusvlei, Fish River Canyon) has no API — their system is notorious for showing "fully booked" when it isn\'t. Every request goes to the team as nwr_pending for manual review. "Manual" is the default for any partner without a connector: a plain email notification, no live check.',
                            'rows' => [
                                ['connector_type', 'Partner', true, 'Select "NWR — Concierge (manual)", or leave blank for "Manual"'],
                            ],
                            'note' => 'No API key, no property code needed.',
                        ],
                    ];
                @endphp

                @foreach ($connectors as $connector)
                    <section class="card" id="{{ $connector['id'] }}">
                        <div class="card-head">
                            <h2>{{ $connector['name'] }}</h2>
                            <span class="badge {{ $connector['badge']['class'] }}">{{ $connector['badge']['label'] }}</span>
                        </div>
                        <p class="card-sub">{{ $connector['intro'] }}</p>

                        <div class="fields">
                            <table class="fields-table">
                                <thead><tr><th>Field</th><th>Where</th><th>Required</th><th>Value</th></tr></thead>
                                <tbody>
                                    @foreach ($connector['rows'] as [$field, $where, $required, $value])
                                        <tr>
                                            <td class="mono">{{ $field }}</td>
                                            <td><span class="where {{ $where === 'Listing' ? 'listing' : '' }}">{{ $where }}</span></td>
                                            <td>
                                                @if ($required)
                                                    <span class="req">Required</span>
                                                @else
                                                    <span class="opt">Optional</span>
                                                @endif
                                            </td>
                                            <td>{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if ($connector['note'])
                            <div class="note"><strong>Note:</strong> {{ $connector['note'] }}</div>
                        @endif
                    </section>
                @endforeach
            </main>

            <footer>
                All credentials in <code>connector_config</code> are stored encrypted in the database
                (<code>encrypted:array</code> cast on the Partner model). "Test connector" (listings table)
                checks a test date 30 days out and reports the result as a notification — without creating a
                real reservation.
            </footer>
        </div>
    </div>
</x-filament-panels::page>
