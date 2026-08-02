<x-filament-panels::page>
    <div class="nwh">
        <style>
            .nwh {
                --ink: #2A2317; --ink-soft: #6B6350; --line: #E4D9BE; --paper-2: #F5F0E3;
                --accent: #2C3E5C; --accent-soft: #DCE2EA; --gold: #B9812E;
                --good: #3F6B4A; --good-bg: #E4EBDF; --flag: #A23E28; --flag-bg: #F2E2D9;
                color: var(--ink);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
                line-height: 1.55;
                max-width: 62rem;
            }
            .dark .nwh {
                --ink: #EDE6D6; --ink-soft: #B3A98E; --line: #3B3526; --paper-2: #242019;
                --accent: #8FA6CC; --accent-soft: #2A3345; --gold: #D9A54F;
                --good: #8FBF9B; --good-bg: #243527; --flag: #E08A6F; --flag-bg: #3A2620;
            }
            .nwh h1, .nwh h2, .nwh h3 {
                font-family: Georgia, "Iowan Old Style", "Times New Roman", serif;
                font-weight: 600; text-wrap: balance; color: var(--ink); margin: 0;
            }
            .nwh .eyebrow {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                font-size: 12px; letter-spacing: .11em; text-transform: uppercase;
                color: var(--gold); font-weight: 700; margin: 0 0 8px;
            }
            .nwh h1 { font-size: 30px; line-height: 1.15; margin-bottom: 6px; }
            .nwh .subtitle { color: var(--ink-soft); font-size: 15.5px; margin: 0 0 28px; max-width: 60ch; }
            .nwh h2 { font-size: 20px; }
            .nwh .section-note { color: var(--ink-soft); font-size: 14px; margin: 4px 0 16px; max-width: 62ch; }
            .nwh h3 { font-size: 15px; margin: 18px 0 6px; color: var(--accent); }
            .nwh p { margin: 0 0 12px; max-width: 68ch; }
            .nwh p:last-child { margin-bottom: 0; }
            .nwh a { color: var(--accent); }

            .nwh section { padding: 28px 0; border-top: 1px solid var(--line); }
            .nwh section:first-of-type { border-top: none; padding-top: 0; }

            .nwh .num {
                display: inline-flex; align-items: center; justify-content: center;
                width: 24px; height: 24px; border-radius: 50%;
                background: var(--accent); color: var(--paper-2);
                font-family: Georgia, serif; font-size: 12.5px; font-weight: 700;
                margin-right: 10px; flex-shrink: 0;
            }
            .nwh .step-head { display: flex; align-items: center; margin-bottom: 2px; }

            .nwh .card {
                background: var(--paper-2); border: 1px solid var(--line); border-radius: 10px;
                padding: 16px 18px; margin: 14px 0;
            }

            .nwh .quickfacts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 8px; }
            .nwh .quickfacts > div {
                background: var(--paper-2); border: 1px solid var(--line); border-radius: 12px;
                padding: 16px 16px 18px;
            }
            .nwh .quickfacts dt { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: var(--gold); font-weight: 700; margin: 0; }
            .nwh .quickfacts dd { margin: 6px 0 0; font-size: 14.5px; }

            @media (max-width: 900px) {
                .nwh .quickfacts { grid-template-columns: 1fr 1fr; }
            }
            @media (max-width: 520px) {
                .nwh .quickfacts { grid-template-columns: 1fr; }
            }

            .nwh ol.steps { list-style: none; margin: 0; padding: 0; }
            .nwh ol.steps li { display: flex; gap: 12px; padding: 9px 0; border-bottom: 1px dashed var(--line); }
            .nwh ol.steps li:last-child { border-bottom: none; }
            .nwh ol.steps li .n { font-family: Georgia, serif; font-weight: 700; color: var(--gold); flex-shrink: 0; width: 18px; }

            .nwh .checklist { list-style: none; margin: 0; padding: 0; }
            .nwh .checklist li { position: relative; padding: 5px 0 5px 28px; font-size: 14.5px; }
            .nwh .checklist li::before {
                content: ""; position: absolute; left: 0; top: 7px; width: 15px; height: 15px;
                border: 1.5px solid var(--ink-soft); border-radius: 4px;
            }

            .nwh table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 14px; }
            .nwh th, .nwh td { text-align: left; padding: 7px 10px; border-bottom: 1px solid var(--line); }
            .nwh th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--ink-soft); font-weight: 700; }
            .nwh td.num-cell, .nwh th.num-cell { text-align: right; font-variant-numeric: tabular-nums; }
            .nwh .table-wrap { overflow-x: auto; }

            .nwh .tag { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .03em; padding: 2px 9px; border-radius: 100px; }
            .nwh .tag.priority { background: var(--flag-bg); color: var(--flag); }
            .nwh .tag.later { background: var(--good-bg); color: var(--good); }

            .nwh .callout {
                border-left: 3px solid var(--flag); background: var(--flag-bg);
                border-radius: 0 8px 8px 0; padding: 12px 16px; margin: 14px 0; font-size: 14px;
            }
            .nwh .callout strong { color: var(--flag); }
            .nwh .callout.good { border-left-color: var(--good); background: var(--good-bg); }
            .nwh .callout.good strong { color: var(--good); }

            .nwh code {
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px;
                background: var(--paper-2); border: 1px solid var(--line); padding: 1px 6px; border-radius: 5px;
            }

            .nwh footer { margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--line); color: var(--ink-soft); font-size: 12.5px; }

            .nwh .layout { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 40px; align-items: start; }
            .nwh nav.toc { position: sticky; top: 32px; display: flex; flex-direction: column; gap: 2px; }
            .nwh nav.toc .navlabel {
                font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em;
                color: var(--ink-soft); margin-bottom: 8px;
            }
            .nwh nav.toc a {
                text-decoration: none; color: var(--ink-soft); font-size: 13.5px; font-weight: 600;
                padding: 7px 10px; border-radius: 7px;
            }
            .nwh nav.toc a:hover { color: var(--ink); background: var(--paper-2); }
            .nwh main { min-width: 0; }
            .nwh section { scroll-margin-top: 28px; }

            @media (max-width: 760px) {
                .nwh .layout { grid-template-columns: 1fr; gap: 20px; }
                .nwh nav.toc { position: static; flex-direction: row; flex-wrap: wrap; gap: 6px; }
                .nwh nav.toc .navlabel { display: none; }
                .nwh nav.toc a { background: var(--paper-2); }
            }
        </style>

        <p class="eyebrow">NamibWay — Working Handbook</p>
        <p class="subtitle">A practical guide for building out NamibWay's property listings and getting the businesses behind them onto the platform. No coding, no technical background needed — just careful research and friendly phone/email work.</p>

        <div class="quickfacts">
            <div><dt>Where you'll work</dt><dd><em>Data Enrichment</em> &amp; <em>Partners</em> (left menu)</dd></div>
            <div><dt>Your account</dt><dd>Your own login — see Section 1</dd></div>
            <div><dt>Two jobs</dt><dd>Complete listings · Contact owners</dd></div>
            <div><dt>Start with</dt><dd>Accommodation, then Activities &amp; Vehicles</dd></div>
        </div>

        <div class="layout">
        <nav class="toc" aria-label="Sections">
            <span class="navlabel">On this page</span>
            <a href="#getting-started">Getting started</a>
            <a href="#job-1">Complete a listing</a>
            <a href="#job-2">Contact owners</a>
            <a href="#priorities">Priorities</a>
            <a href="#reporting">Reporting</a>
            <a href="#rules">What not to do</a>
        </nav>

        <main>
        <section id="getting-started">
            <div class="step-head"><span class="num">1</span><h2>Getting started</h2></div>
            <p class="section-note">NamibWay is an AI travel-planning site for Namibia. Travelers chat with "Kaia," our AI trip assistant, and get a bookable day-by-day itinerary — pulled together from real accommodation, activities, restaurants and vehicle rentals in our database. Your work directly improves what Kaia can recommend.</p>

            <p>You have your own login here — a username/email and a temporary password, sent separately. Please don't share this login with anyone or reuse Adriano's account; each person needs their own so we can tell whose changes are whose, and so we can turn off access cleanly if your role ever changes.</p>

            <div class="callout good">
                <strong>First 10 minutes:</strong> click <em>Data Enrichment</em> in the left-hand menu. You'll land on a table of every listing with a completion score. That table and the stat tiles above it are your dashboard for everything in Section 2.
            </div>
        </section>

        <section id="job-1">
            <div class="step-head"><span class="num">2</span><h2>Job 1 — Complete a listing profile</h2></div>
            <p class="section-note">Every listing (a lodge, tour, restaurant, or rental vehicle) has a completion score out of 100. Your job is to raise it by filling in what's missing — using the business's own website, Google, Facebook, TripAdvisor, or a phone call when nothing is online.</p>

            <div class="table-wrap">
            <table>
                <tr><th>Field</th><th class="num-cell">Weight</th><th>Where to find it</th></tr>
                <tr><td>Description (2–4 sentences, in English)</td><td class="num-cell">20%</td><td>Their website "About" page, or write it yourself from what you learn about them</td></tr>
                <tr><td>Photos (1 hero + a few gallery shots)</td><td class="num-cell">20%</td><td>Their own website/social media — <strong>see photo rules below</strong></td></tr>
                <tr><td>Website</td><td class="num-cell">10%</td><td>Google the business name + "Namibia"</td></tr>
                <tr><td>Email</td><td class="num-cell">10%</td><td>Their website contact page, or ask by phone</td></tr>
                <tr><td>Phone</td><td class="num-cell">10%</td><td>Usually already filled in</td></tr>
                <tr><td>Address</td><td class="num-cell">10%</td><td>Website / Google Maps listing</td></tr>
                <tr><td>GPS pin</td><td class="num-cell">10%</td><td>Search the name in Google Maps, right-click → coordinates</td></tr>
                <tr><td>Facilities / activities on offer</td><td class="num-cell">10%</td><td>Their website, brochures</td></tr>
            </table>
            </div>

            <h3>Step by step</h3>
            <ol class="steps">
                <li><span class="n">1</span>In <em>Data Enrichment</em>, sort or filter by completion score to find the lowest-scoring listings first.</li>
                <li><span class="n">2</span>Click a row to open it. It opens in tabs: <em>Basic, Contact, Address, Description, Photos, Metadata</em>.</li>
                <li><span class="n">3</span>Research the business (website, Google, socials). Fill in whatever tab matches what you found — you don't have to do every tab in one sitting.</li>
                <li><span class="n">4</span>Write the description in your own words from what you find — don't copy-paste text wholesale from their website.</li>
                <li><span class="n">5</span>Save. The completion score updates automatically.</li>
            </ol>

            <div class="callout">
                <strong>Photo rights — please don't skip this:</strong> only use a photo if the business itself published it (their own site or official social page), or if you have their explicit OK to use it. Don't pull random photos from Google Images, TripAdvisor reviews, or other travelers' posts. If in doubt, leave the photo blank and flag it rather than guess — a wrongly-used photo is a bigger problem for us than a missing one.
            </div>

            <p><strong>Definition of "done":</strong> a listing is in good shape once its score is <strong>90% or higher</strong>. You don't need to chase 100% — some fields (like "activities on offer") genuinely don't apply to every place.</p>
        </section>

        <section id="job-2">
            <div class="step-head"><span class="num">3</span><h2>Job 2 — Contact the owners</h2></div>
            <p class="section-note">Most of these businesses don't know they're listed on NamibWay yet. We want to let them know, invite them to take over (claim) their own page, and — where they use a booking system — get that connected.</p>

            <h3>3.1 — Send the claim invite</h3>
            <ol class="steps">
                <li><span class="n">1</span>Once a listing has a working email address on file, open its <strong>Partner</strong> record and use the <strong>"Invite Owner"</strong> action.</li>
                <li><span class="n">2</span>This sends an email with a personal link letting them log in (no password needed) and edit their own page: fix details, upload their own photos, tell us what booking system they use.</li>
                <li><span class="n">3</span>The system tracks who's been invited, who's replied, and who's declined — no spreadsheet needed, it's all visible on the Partner list.</li>
            </ol>

            <h3>3.2 — When there's no email on file yet</h3>
            <p>This is likely the bulk of the outreach work. For these, find a contact channel first (their website contact form, a listed phone number, Facebook page) — then either email them directly with the claim link, or call and offer to fill in the confirmed details yourself over the phone if they're not comfortable online. Both are fine — the goal is a real human confirming their details, not a specific channel.</p>

            <h3>3.3 — Following up</h3>
            <p>Small operators often won't respond to a first email. For accommodation, activity operators and vehicle rental companies especially, a short follow-up call 5–7 days later is worth it — these listings matter most to trip quality (see priorities below). For restaurants, one email is usually enough; a mass follow-up call round isn't a good use of time there.</p>

            <div class="callout">
                <strong>If a partner asks about money, contracts, or commission terms:</strong> don't negotiate or promise anything — note what they asked and pass it to Adriano or Till. Your role is data and relationships, not deal terms.
            </div>

            <h3>3.4 — Booking systems (if they mention one)</h3>
            <p>If an owner tells you they use a booking system — ResRequest, NightsBridge, hopeCloud, Wetu, or Namibia Wildlife Resorts' own system — just note which one on their Partner record (<em>Connector Type</em> field; see the <a href="{{ \App\Filament\Pages\BookingConnectorGuide::getUrl() }}">Booking Connector Guide</a> for details). You don't need to configure logins or API access yourself; that's a technical step we'll follow up on once we know which system is involved. If they don't use any booking software at all, that's completely normal for smaller operators — mark it "Manual" and move on.</p>
        </section>

        <section id="priorities">
            <div class="step-head"><span class="num">4</span><h2>What to work on first</h2></div>
            <p class="section-note">276 listings need work in total. Not all matter equally: accommodation and activities are what Kaia actually builds itineraries around, so a well-documented lodge helps far more than a well-documented restaurant. Work in this order:</p>

            <div class="table-wrap">
            <table>
                <tr><th>Type</th><th class="num-cell">Count</th><th>Priority</th></tr>
                <tr><td>Accommodation (lodges, guesthouses, campsites)</td><td class="num-cell">37</td><td><span class="tag priority">Do first</span></td></tr>
                <tr><td>Activities &amp; tours</td><td class="num-cell">69</td><td><span class="tag priority">Do first</span></td></tr>
                <tr><td>Vehicle rental</td><td class="num-cell">7</td><td><span class="tag priority">Do first</span></td></tr>
                <tr><td>Restaurants</td><td class="num-cell">163</td><td><span class="tag later">Steady, ongoing</span></td></tr>
            </table>
            </div>
            <p>Treat the first three rows (113 listings) as the first milestone — get those to 90%+ and their owners contacted before working through the restaurant list, which is larger but individually lower-stakes.</p>
        </section>

        <section id="reporting">
            <div class="step-head"><span class="num">5</span><h2>Keeping us posted</h2></div>
            <p class="section-note">No formal reporting tool needed — a short weekly note is enough. Include:</p>
            <ul class="checklist">
                <li>How many listings you brought to 90%+ this week</li>
                <li>How many owners you invited / reached by phone</li>
                <li>How many claimed their listing, and how many declined (and why, if they said)</li>
                <li>Anything you got stuck on, or any business that raised a money/contract question</li>
            </ul>
        </section>

        <section id="rules">
            <div class="step-head"><span class="num">6</span><h2>What not to do</h2></div>
            <ul class="checklist">
                <li>Don't unpublish, delete, or merge listings — flag duplicates or clearly wrong entries instead of removing them yourself</li>
                <li>Don't enter or discuss any payment details, ever</li>
                <li>Don't use a photo unless you're sure the business published it themselves or gave permission</li>
                <li>Don't promise commission rates, pricing, or contract terms to a partner — pass those questions on</li>
            </ul>
        </section>
        </main>
        </div>

        <footer>
            Questions any time — reach out to Adriano or Till directly. This handbook covers the day-to-day; anything unusual is always fine to ask about rather than guess.
        </footer>
    </div>
</x-filament-panels::page>
