<x-filament-panels::page>
    {{--
        Same stylesheet as the Booking Connector Guide, deliberately: these two
        pages are read by the same people and a second visual language would
        only make them look like different products. If that one's styling
        changes, change this with it.
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
                <div class="eyebrow">NamibWay Admin &middot; Customer websites</div>
                <p>How we build a business its own website, what the owner has to give us, what they can
                    change themselves afterwards, and the three things we cannot do yet. Read the last
                    section before promising anything on a call.</p>
            </header>

            <nav class="toc" aria-label="Sections">
                <span class="navlabel">On this page</span>
                <a href="#workflow">How we set one up</a>
                <a href="#ask">What to ask the owner for</a>
                <a href="#sections">What is on the site</a>
                <a href="#enquiries">Enquiries</a>
                <a href="#booking">Booking</a>
                <a href="#legal">Legal pages</a>
                <a href="#owner">What the owner changes</a>
                <a href="#not-yet">What we do not offer yet</a>
                <a href="#trouble">When something looks wrong</a>
            </nav>

            <main>
                <section id="workflow">
                    <div class="workflow">
                        <div class="step">
                            <span class="num">1</span>
                            <h3>Create the draft</h3>
                            <p>Open the listing, <strong>Website</strong> tab, press <em>Create website</em>.
                                A minute later there is a private draft built from the listing: its text, its
                                photographs, its address, its opening hours. Nothing is public.</p>
                        </div>
                        <div class="step">
                            <span class="num">2</span>
                            <h3>Show it to the owner</h3>
                            <p>Send them the link from the same tab. It works without a password, so treat it
                                like one. Fix what they object to, then send it again.</p>
                        </div>
                        <div class="step">
                            <span class="num">3</span>
                            <h3>Publish</h3>
                            <p>Press <em>Publish</em>. Tick the confirmation and write down who at the business
                                agreed. Only then does it get its clean address and become findable.</p>
                        </div>
                    </div>

                    <div class="note">
                        <strong>Pressing create twice is safe.</strong> The second press refreshes the draft
                        from the listing and leaves anything edited on the website since exactly as it is.
                        The button says <em>Rebuild</em> once a site exists, and the report says what it kept.
                    </div>
                </section>

                <section class="card" id="ask">
                    <div class="card-head">
                        <h2>What to ask the owner for</h2>
                        <span class="badge manual">Before the call ends</span>
                    </div>
                    <p class="card-sub">We can build a site from the listing alone, and it will look thin.
                        These five things are what turn it into something they are proud to send people.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th>What</th><th>Why it matters</th><th>Needed</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Photographs they own</strong></td>
                                    <td>The hero image is the first thing anybody sees. We may only publish
                                        pictures that are theirs or ours &mdash; never a third-party
                                        directory&rsquo;s. Ask explicitly: <em>are these your own photos?</em></td>
                                    <td class="req">Yes</td>
                                </tr>
                                <tr>
                                    <td><strong>Logo</strong></td>
                                    <td>Goes in the bar at the top. A wide mark on a transparent background
                                        reads best. Without one we set their name in type, which is a
                                        finished result, not a placeholder.</td>
                                    <td class="opt">Optional</td>
                                </tr>
                                <tr>
                                    <td><strong>Opening hours and prices</strong></td>
                                    <td>Both have their own section. A restaurant without hours and a
                                        activity without a price list look unfinished to a visitor.</td>
                                    <td class="opt">Recommended</td>
                                </tr>
                                <tr>
                                    <td><strong>Company details for the legal notice</strong></td>
                                    <td>Registered name, postal address, who is responsible for the content.
                                        We write the first version from the listing; they correct it.</td>
                                    <td class="req">Yes</td>
                                </tr>
                                <tr>
                                    <td><strong>The email that should receive enquiries</strong></td>
                                    <td>This is where the requests land. Get the one somebody actually reads,
                                        not the one on the letterhead.</td>
                                    <td class="req">Yes</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note">
                        <strong>Photographs are the one thing we cannot fix for them.</strong> A generated
                        draft with no usable pictures will look poor no matter what else we do, and it is the
                        most common reason a demo fails to close. Ask for them first, not last.
                    </div>
                </section>

                <section class="card" id="sections">
                    <div class="card-head">
                        <h2>What is on the site</h2>
                        <span class="badge live">One page</span>
                    </div>
                    <p class="card-sub">One page, in this order. A section with nothing in it does not render,
                        so a business that has told us little gets a short site rather than a half-finished
                        one.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Section</th><th>What it shows</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Opening screen</strong></td><td>Photograph, town, a short line and a paragraph. The big line is <em>not</em> their name &mdash; the name is already in the bar above it.</td></tr>
                                <tr><td><strong>What we offer</strong></td><td>Their highlights, or their amenity list, each with a small mark.</td></tr>
                                <tr><td><strong>About</strong></td><td>Their description.</td></tr>
                                <tr><td><strong>Gallery</strong></td><td>Up to twelve photographs.</td></tr>
                                <tr><td><strong>Opening hours</strong></td><td>Per day.</td></tr>
                                <tr><td><strong>Price list</strong></td><td>Free-form: name, price, a note.</td></tr>
                                <tr><td><strong>Booking</strong></td><td>Live availability and prices &mdash; only for properties in our booking system. See below.</td></tr>
                                <tr><td><strong>Request / enquiry</strong></td><td>The contact form. Every business gets this one.</td></tr>
                                <tr><td><strong>Find us</strong></td><td>Address and map.</td></tr>
                                <tr><td><strong>Get in touch</strong></td><td>Telephone, email, WhatsApp, social links.</td></tr>
                                <tr><td><strong>Footer</strong></td><td>Their copyright, Privacy, Legal notice, and &ldquo;Powered by NamibWay&rdquo;.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card" id="enquiries">
                    <div class="card-head">
                        <h2>Enquiries</h2>
                        <span class="badge live">Every business</span>
                    </div>
                    <p class="card-sub">This is the part to explain properly on the call, because it is what
                        they are actually buying: a website that brings them requests they can answer in one
                        click.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Step</th><th>What happens</th></tr></thead>
                            <tbody>
                                <tr><td><strong>A visitor sends the form</strong></td><td>Accommodation is asked arrival and departure. An activity or a restaurant is asked a date and a time, and never a departure.</td></tr>
                                <tr><td><strong>The business gets an email</strong></td><td>All the details, plus two buttons: <em>Confirm</em> and <em>Decline</em>. No login, no app, works from a phone.</td></tr>
                                <tr><td><strong>The visitor gets a copy</strong></td><td>Immediately, with what they wrote. It says clearly that nothing is booked yet.</td></tr>
                                <tr><td><strong>The business presses one</strong></td><td>The visitor is sent a confirmation or a polite refusal automatically. We record the outcome.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note">
                        <strong>Tell them the buttons expire after three days.</strong> After that they can
                        still answer from their partner area &mdash; the request does not disappear &mdash; but
                        the one-click links in that particular email stop working.
                    </div>
                </section>

                <section class="card" id="booking">
                    <div class="card-head">
                        <h2>Booking</h2>
                        <span class="badge content">Only some properties</span>
                    </div>
                    <p class="card-sub">Be precise here. Promising a booking engine we do not deliver is the
                        one mistake on this page that costs a customer.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Situation</th><th>What the site does</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><strong>The property keeps its rooms and rates in our booking system</strong></td>
                                    <td>The booking section appears. A visitor picks dates and sees what is
                                        free and what it costs, taxes included, read live from the property&rsquo;s
                                        own calendar at that moment. Nothing is copied, so a price on their
                                        website can never be out of date. The visitor then clicks through to
                                        namibway.com to request those dates.</td>
                                </tr>
                                <tr>
                                    <td><strong>Everybody else</strong></td>
                                    <td>The booking section simply does not appear. The enquiry form does the
                                        work, exactly as described above.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note">
                        <strong>The booking is not completed on their own site yet.</strong> The visitor is
                        handed over to namibway.com for the last step. Do not promise otherwise.
                    </div>
                </section>

                <section class="card" id="legal">
                    <div class="card-head">
                        <h2>Legal pages</h2>
                        <span class="badge manual">They own these</span>
                    </div>
                    <p class="card-sub">Every site has a Privacy page, a Legal notice and a copyright line,
                        linked from the footer.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Point</th><th>What to say</th></tr></thead>
                            <tbody>
                                <tr><td><strong>We write the first version</strong></td><td>From what we know: what the form collects, where it goes, that there is no tracking. Their own details come off the listing.</td></tr>
                                <tr><td><strong>It is theirs</strong></td><td>They own the text and they are responsible for it. We are responsible for hosting and the technical operation, and the legal notice says so.</td></tr>
                                <tr><td><strong>They confirm it when we publish</strong></td><td>The publish step asks for a tick and a name. That same tick is how they accept our website terms. We store who and when.</td></tr>
                                <tr><td><strong>They can change it any time</strong></td><td>From their own partner area, or ask us.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note">
                        <strong>Do not publish before somebody at the business has seen it.</strong> Publishing
                        puts a page on the open internet under their name and makes it findable. A draft is
                        research; a published site is a statement they are responsible for.
                    </div>
                </section>

                <section class="card" id="owner">
                    <div class="card-head">
                        <h2>What the owner changes themselves</h2>
                        <span class="badge live">Partner area</span>
                    </div>
                    <p class="card-sub">In their own partner area, on their listing, they have the same four
                        buttons we do. Everything else goes through us, which is what the offer says.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Button</th><th>What it changes</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Opening screen</strong></td><td>The big line, the paragraph under it, and whether the town is shown.</td></tr>
                                <tr><td><strong>Logo</strong></td><td>Upload or remove it.</td></tr>
                                <tr><td><strong>Fonts &amp; title size</strong></td><td>Headings, body text, the face and size of their name &mdash; separately for phones and wide screens.</td></tr>
                                <tr><td><strong>Privacy, legal notice &amp; copyright</strong></td><td>All three texts.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note">
                        <strong>Their <em>Create website</em> button is greyed out on purpose.</strong> It
                        switches on with the subscription. Until billing exists that is us, by invoice, and we
                        press create for them.
                    </div>
                </section>

                <section class="card" id="not-yet">
                    <div class="card-head">
                        <h2>What we do not offer yet</h2>
                        <span class="badge manual">Do not promise</span>
                    </div>
                    <p class="card-sub">Say these plainly if asked. Every one of them is planned, none is
                        built, and an owner who was promised one will remember.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>Not yet</th><th>What we say instead</th></tr></thead>
                            <tbody>
                                <tr><td><strong>Their own domain</strong></td><td>Today the site lives on a NamibWay subdomain. Their own domain is coming; do not take money for it yet.</td></tr>
                                <tr><td><strong>Editing the whole site themselves</strong></td><td>They change the four things above; the rest is us. That is the offer, not a limitation to apologise for.</td></tr>
                                <tr><td><strong>Booking completed on their own site</strong></td><td>See the booking section.</td></tr>
                                <tr><td><strong>A language switcher</strong></td><td>The site is in one language today.</td></tr>
                                <tr><td><strong>Automatic billing</strong></td><td>Invoice, by hand, for now.</td></tr>
                                <tr><td><strong>Visitor statistics</strong></td><td>We set no tracking at all, which is also why the site needs no cookie banner. Worth selling as a feature rather than apologising for.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card" id="trouble">
                    <div class="card-head">
                        <h2>When something looks wrong</h2>
                    </div>

                    <div class="fields">
                        <table class="fields-table">
                            <thead><tr><th>What you see</th><th>What it means</th></tr></thead>
                            <tbody>
                                <tr><td><strong>The site has no photographs</strong></td><td>Either the listing has none, or the only ones it has come from a third-party directory and we are not allowed to publish those. Ask the owner for their own.</td></tr>
                                <tr><td><strong>A section is missing</strong></td><td>It has nothing to show. Fill the corresponding field on the listing and rebuild.</td></tr>
                                <tr><td><strong>No booking section</strong></td><td>The property is not in our booking system, or has no rooms and rates entered. Expected.</td></tr>
                                <tr><td><strong>Publish is refused</strong></td><td>The message names the reason &mdash; usually photographs we are only allowed to use while prospecting. Replace them with the owner&rsquo;s own.</td></tr>
                                <tr><td><strong>The address is a long link with a token</strong></td><td>It is still a draft. Publish it and it gets its clean address.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <footer>
                Questions this page does not answer belong in the Website tab on the listing itself, which
                always shows the current state of that one site. For what the software does and why, see
                <code>WEBSITE_BUILDER.md</code> and <code>PROJECT_STATUS.md</code> &sect; 4.
            </footer>
        </div>
    </div>
</x-filament-panels::page>
