<x-filament-panels::page>
    {{--
        Same visual language as the Booking Connector Guide: the shared
        `.nw-guide` stylesheet, scoped so it cannot leak into the panel's own
        chrome, with dark mode following Filament's toggle.

        Two components added on top of it — a role badge, because every
        instruction here has to say *who* does the thing, and a "what you can
        tell the partner" block, because the sentence somebody says on the
        phone is content and not decoration.
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

        .nw-guide .who { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 5px; white-space: nowrap; }
        .nw-guide .who.us { background: var(--rust); color: var(--paper); }
        .nw-guide .who.dev { background: var(--sage); color: var(--paper); }
        .nw-guide .who.partner { background: var(--sand-dark); color: var(--ink-soft); }
        .nw-guide .who.desk { background: var(--gold); color: var(--paper); }

        .nw-guide .say { margin-top: 14px; font-size: 14px; background: var(--sage-light); border-left: 3px solid var(--sage); border-radius: 0 8px 8px 0; padding: 12px 16px; }
        .nw-guide .say .lbl { display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--sage); margin-bottom: 5px; }
        .nw-guide .say p { margin: 0; font-style: italic; }

        .nw-guide .warn { margin-top: 14px; font-size: 13.5px; background: var(--danger-light); border-left: 3px solid var(--danger); border-radius: 0 8px 8px 0; padding: 12px 16px; }
        .nw-guide .warn strong { color: var(--danger); }

        .nw-guide ul.plain { margin: 0; padding-left: 1.1rem; font-size: 14px; }
        .nw-guide ul.plain li { margin-bottom: 6px; }
        .nw-guide ul.plain li:last-child { margin-bottom: 0; }

        .nw-guide section > h2 { font-size: 21px; margin-bottom: 6px; scroll-margin-top: 28px; }
        .nw-guide section > p.lede { color: var(--ink-soft); font-size: 14px; max-width: 66ch; margin: 0 0 18px; }
    </style>

    <div class="nw-guide">
        <div class="page">
            <header class="header">
                <div class="eyebrow">NamibWay Admin &middot; Booking system</div>
                <p>What the money side does, what a partner may decide for themselves, and who sets up what.
                    Written for the person with a lodge owner on the phone as much as for the person
                    operating the panel &mdash; the sales answer and the true answer are the same answer here.</p>
            </header>

            <nav class="toc" aria-label="Sections">
                <span class="navlabel">On this page</span>
                <a href="#flow">How money moves</a>
                <a href="#models">The three models</a>
                <a href="#rates">Commission &amp; deposit</a>
                <a href="#setup">Setting a partner up</a>
                <a href="#gateway">Connecting a gateway</a>
                <a href="#desk">Day to day at the desk</a>
                <a href="#claims">What we may say</a>
            </nav>

            <main>
                <section id="flow">
                    <h2>How money moves</h2>
                    <p class="lede">Three steps, in this order, and each one writes its result down so the next
                        one cannot disagree with it.</p>

                    <div class="workflow">
                        <div class="step">
                            <span class="num">1</span>
                            <h3>The rates are resolved</h3>
                            <p>Commission and deposit are looked up most-specific-first: this listing, then the
                                partner, then the platform default. An empty field means &ldquo;use the level
                                above&rdquo;, never zero.</p>
                        </div>
                        <div class="step">
                            <span class="num">2</span>
                            <h3>The booking freezes them</h3>
                            <p>Both rates and both amounts are written onto the booking when it is taken.
                                Changing a rate next season never rewrites what a past booking earned.</p>
                        </div>
                        <div class="step">
                            <span class="num">3</span>
                            <h3>Money is recorded</h3>
                            <p>Every payment lands on the stay&rsquo;s folio, whether it was cash at the desk or a
                                card online. The balance and the paid total are stored, so every screen reads one
                                number.</p>
                        </div>
                    </div>

                    <div class="note"><strong>Nothing is ever edited or deleted.</strong> A refund is a negative
                        line on the same folio. A line entered by mistake is <em>corrected</em>, which leaves both
                        the original and the correction standing. An issued invoice cannot be changed at all &mdash;
                        it is put right with a credit note. If somebody asks why, the answer is that
                        &ldquo;did we give that money back, or did we never have it?&rdquo; has two different
                        answers and an accountant needs to be able to tell them apart a year later.</div>
                </section>

                <section id="models">
                    <h2>The three settlement models</h2>
                    <p class="lede">We do not force one arrangement on a partner. Which of the three they are on is
                        decided by <strong>one number</strong>: the share of the booking we collect up front. There is
                        no separate setting, and there deliberately cannot be one &mdash; that is what stops a partner
                        being configured into an arrangement their deposit contradicts.</p>

                    <div class="overview-wrap" style="margin-bottom: 24px;">
                        <table class="overview">
                            <thead>
                                <tr><th>Deposit</th><th>Model</th><th>Guest pays</th><th>Between us and the partner</th><th>Guest&rsquo;s invoice</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="mono">0&nbsp;%</td>
                                    <td class="name">Agency</td>
                                    <td>Everything to the property</td>
                                    <td>We invoice them for commission</td>
                                    <td>The property</td>
                                </tr>
                                <tr>
                                    <td class="mono">1&ndash;99&nbsp;%</td>
                                    <td class="name">Split <em>(default)</em></td>
                                    <td>Deposit to us, balance at the property</td>
                                    <td>Deposit minus commission &mdash; often nothing</td>
                                    <td>The property</td>
                                </tr>
                                <tr>
                                    <td class="mono">100&nbsp;%</td>
                                    <td class="name">Merchant</td>
                                    <td>Everything to us</td>
                                    <td>We pay out the folio less commission</td>
                                    <td>NamibWay</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <section class="card" id="split" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <h2>Split &mdash; deposit to us, balance at the property</h2>
                            <span class="badge live">&#9679; Default</span>
                        </div>
                        <p class="card-sub">The guest pays a deposit when they book and the rest at the property.
                            Our commission is already inside the deposit we are holding, so nothing has to be
                            invoiced and nothing has to be chased.</p>
                        <ul class="plain">
                            <li><strong>Set the deposit equal to the commission and no money moves between us and
                                the partner at all.</strong> No payout run, no statement, no reconciliation. That
                                is why it is the default and why the deposit floor follows the commission rate.</li>
                            <li>Where the deposit is higher, the difference is genuinely owed to the partner.</li>
                        </ul>
                        <div class="say">
                            <span class="lbl">What you can tell the partner</span>
                            <p>&ldquo;Your guests pay a deposit to us and settle the rest with you on arrival. If we
                                set the deposit at our commission, there is never an invoice between us and never a
                                payout to wait for &mdash; it simply cancels out.&rdquo;</p>
                        </div>
                    </section>

                    <section class="card" id="agency" style="margin-bottom: 20px;">
                        <div class="card-head">
                            <h2>Agency &mdash; the property collects</h2>
                            <span class="badge manual">&#9679; We unlock it</span>
                        </div>
                        <p class="card-sub">The guest pays the property directly. We touch no guest money and
                            invoice the partner for commission afterwards.</p>
                        <ul class="plain">
                            <li>The easiest model to say yes to, and the most expensive one for us to run.</li>
                            <li>It is the only arrangement where <strong>our money depends on somebody else paying
                                an invoice</strong>. It needs payment terms, a reminder process, and the only lever
                                we have if a partner does not pay is switching their bookings off.</li>
                            <li>A partner therefore cannot choose 0&nbsp;% themselves. We enable it per partner
                                (<em>Partner &rarr; Commission and deposit &rarr; &ldquo;May collect no
                                deposit at all&rdquo;</em>).</li>
                        </ul>
                        <div class="say">
                            <span class="lbl">What you can tell the partner</span>
                            <p>&ldquo;You keep control of the money &mdash; your guests pay you directly and we invoice
                                you for commission afterwards. That is something we agree case by case rather than
                                switch on by default.&rdquo;</p>
                        </div>
                        <div class="warn"><strong>Do not offer this on the phone as if it were a checkbox.</strong>
                            Payment terms and what happens to a partner who does not pay are commercial decisions
                            that have not been made yet. Say we can do it and that it needs an agreement.</div>
                    </section>

                    <section class="card" id="merchant">
                        <div class="card-head">
                            <h2>Merchant &mdash; we collect</h2>
                            <span class="badge content">&#9679; Needs a merchant account</span>
                        </div>
                        <p class="card-sub">The guest pays us the whole amount and we pay the property the folio
                            less commission.</p>
                        <ul class="plain">
                            <li>Our commission is certain, because we simply do not pay it out.</li>
                            <li>In exchange we hold other people&rsquo;s money: payouts, refunds, chargebacks and a
                                different regulatory posture. <strong>That is a company decision and it has not been
                                made.</strong></li>
                            <li>Some partners will actively want it &mdash; a small owner-run lodge with no card
                                facility is exactly the case where &ldquo;you do all of it&rdquo; is the selling
                                point.</li>
                        </ul>
                        <div class="say">
                            <span class="lbl">What you can tell the partner</span>
                            <p>&ldquo;We can take the whole payment and pay you out the balance less commission. If
                                you have no card facility of your own, that may be the simplest thing for you.&rdquo;</p>
                        </div>
                    </section>
                </section>

                <section id="rates">
                    <h2>Commission and deposit &mdash; who sets what</h2>
                    <p class="lede">Two rates with two different owners. This distinction is worth being exact about
                        on the phone, because it is the one a partner will test.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th>Rate</th><th>Whose</th><th>Set where</th><th>What it is taken on</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="mono">Commission</td>
                                    <td><span class="who us">NamibWay only</span></td>
                                    <td>Settings &rarr; Commission and deposits, or per partner / per listing</td>
                                    <td>The stay <strong>before</strong> VAT and the tourism levy</td>
                                </tr>
                                <tr>
                                    <td class="mono">Deposit</td>
                                    <td><span class="who partner">The partner</span></td>
                                    <td>Their own panel, per listing &mdash; within the range we allow</td>
                                    <td>The whole folio, tax included</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note"><strong>We never take a cut of the government&rsquo;s VAT or the NTB&rsquo;s
                        tourism levy.</strong> Say that out loud to an operator &mdash; it is the kind of thing they
                        have been caught by before, and the booking carries the base it was worked out from so they
                        can check it. A property&rsquo;s <em>own</em> fees (a conservancy fee, a park permit) are
                        revenue and do count.</div>

                    <div class="note"><strong>The partner panel has no commission field at all.</strong> Not a
                        disabled one &mdash; the field does not exist in that form, so there is nothing to post to.
                        A partner can see what they pay and cannot change it.</div>
                </section>

                <section id="setup">
                    <h2>Setting a partner up</h2>
                    <p class="lede">In order. Steps 1 to 4 are ours; step 5 is the partner&rsquo;s and step 6 only
                        matters once a real gateway exists.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th style="width: 2.5rem;">#</th><th>Who</th><th>Where</th><th>What</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="mono">1</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Admin &rarr; Settings &rarr; <strong>Commission and deposits</strong></td>
                                    <td>The platform defaults: commission, default deposit, and the lowest deposit a
                                        partner may set. Leave the floor empty to keep it following the commission.
                                        Set once; you rarely touch it again.</td>
                                </tr>
                                <tr>
                                    <td class="mono">2</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Admin &rarr; Content &rarr; Partners &rarr; <strong>Bookings</strong> tab</td>
                                    <td><strong>What they bought</strong>: booking system only, or booking system and
                                        front desk. This hides or shows screens and changes nothing that is stored,
                                        so a partner can be upgraded later without a migration.</td>
                                </tr>
                                <tr>
                                    <td class="mono">3</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Same partner &rarr; <strong>Commission and deposit</strong> tab</td>
                                    <td>Only if something was negotiated. An empty field follows the platform
                                        default and keeps following it. This is also where 0&nbsp;% is unlocked.</td>
                                </tr>
                                <tr>
                                    <td class="mono">4</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Admin &rarr; Content &rarr; Listings</td>
                                    <td>Only where one property in a group is different. Listing beats partner
                                        beats platform.</td>
                                </tr>
                                <tr>
                                    <td class="mono">5</td>
                                    <td><span class="who partner">Partner</span></td>
                                    <td>Partner panel &rarr; My Listings &rarr; <strong>Deposit and commission</strong></td>
                                    <td>They set their own deposit. The panel tells them, as they type, which model
                                        that puts them on. Below the floor it refuses and says what the floor is.</td>
                                </tr>
                                <tr>
                                    <td class="mono">6</td>
                                    <td><span class="who dev">Developer</span></td>
                                    <td>Server <code>.env</code></td>
                                    <td>The payment gateway. Not in any panel &mdash; see the next section.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="warn"><strong>The deposit amount is frozen when a booking is taken.</strong> So a
                        booking made <em>before</em> a partner had a deposit rate has no deposit on it and shows no
                        payment link. That is correct, not a fault &mdash; it is what stops a rate change rewriting
                        the past. New bookings pick it up immediately.</div>
                </section>

                <section id="gateway">
                    <h2>Connecting a payment gateway</h2>
                    <p class="lede">The gateway is deliberately not a panel setting. It is server configuration,
                        because the credentials are ours rather than a partner&rsquo;s and a wrong one breaks
                        everybody at once.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th>Provider</th><th>Namibia</th><th>Settles</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="name">DPO Pay by Network</td>
                                    <td>Yes &mdash; team in Windhoek</td>
                                    <td class="mono">NAD</td>
                                    <td><strong>The one to use.</strong> Built, needs a merchant account.</td>
                                </tr>
                                <tr>
                                    <td class="name">Demo</td>
                                    <td>&mdash;</td>
                                    <td>&mdash;</td>
                                    <td>The default. Works completely, moves no money.</td>
                                </tr>
                                <tr>
                                    <td class="name">Paystack</td>
                                    <td class="req">No merchant accounts</td>
                                    <td class="mono">ZAR</td>
                                    <td>Built. Needs a South African entity.</td>
                                </tr>
                                <tr>
                                    <td class="name">Peach Payments</td>
                                    <td class="req">Announced, not available</td>
                                    <td>&mdash;</td>
                                    <td>Watch it &mdash; ResRequest already integrates it.</td>
                                </tr>
                                <tr>
                                    <td class="name">Ozow, Netcash</td>
                                    <td class="req">South African bank rails</td>
                                    <td class="mono">ZAR</td>
                                    <td>Not applicable.</td>
                                </tr>
                                <tr>
                                    <td class="name">PayToday</td>
                                    <td>Namibian (Nedbank)</td>
                                    <td class="mono">NAD</td>
                                    <td>A wallet, not a card acquirer. A method to add later.</td>
                                </tr>
                                <tr>
                                    <td class="name">FNB / Bank Windhoek / Standard Bank</td>
                                    <td>Acquiring exists</td>
                                    <td class="mono">NAD</td>
                                    <td>A banking relationship, usually behind a gateway anyway.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fields" style="margin-top: 20px;">
                        <table class="fields-table">
                            <thead>
                                <tr><th style="width: 2.5rem;">#</th><th>Who</th><th>What</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="mono">1</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Apply for a DPO merchant account and get the fee schedule. Ask them for two
                                        things while you have them: the <code>verifyToken</code> result-code table,
                                        and our account&rsquo;s <code>ServiceType</code>.</td>
                                </tr>
                                <tr>
                                    <td class="mono">2</td>
                                    <td><span class="who dev">Developer</span></td>
                                    <td>Set <code>PAYMENTS_PROVIDER=dpo</code>, <code>DPO_COMPANY_TOKEN</code> and
                                        <code>DPO_SERVICE_TYPE</code> in the server <code>.env</code>, then deploy.
                                        Leave it on <code>demo</code> until the account is real.</td>
                                </tr>
                                <tr>
                                    <td class="mono">3</td>
                                    <td><span class="who dev">Developer</span></td>
                                    <td>Give DPO the notification URL <code>/api/payments/callback/dpo</code>, and
                                        add the failure codes from step 1 to
                                        <code>config/payments.php</code>.</td>
                                </tr>
                                <tr>
                                    <td class="mono">4</td>
                                    <td><span class="who us">NamibWay</span></td>
                                    <td>Take one real booking of your own and pay it. Refund it. Then hand it to a
                                        partner.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note"><strong>Until step 1 is done the demo provider handles everything.</strong> It
                        runs the whole flow &mdash; pay, decline, abandon, refund, and the callback that arrives
                        before the guest gets back &mdash; with no account and no internet. That is what makes the
                        payment flow demonstrable in a meeting today. Every page it shows says it is a
                        demonstration.</div>

                    <div class="warn"><strong>Why an unpaid DPO transaction stays &ldquo;waiting&rdquo; rather than
                        &ldquo;declined&rdquo;.</strong> We only treat DPO&rsquo;s <code>000</code> (paid) as final.
                        Every other code is reported as still waiting until somebody has their code table, because a
                        code guessed to mean &ldquo;declined&rdquo; would write off a payment that may have arrived.
                        If a lodge asks why an attempt sits there, that is the answer.</div>
                </section>

                <section id="desk">
                    <h2>Day to day at the desk</h2>
                    <p class="lede">Everything happens in the stay drawer, which opens from a bar on the calendar,
                        a row on the arrivals board, or the unpaid list.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th>Action</th><th>Who</th><th>What it does</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="mono">Record a payment</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>Cash, card, bank transfer or other. Cash and card count as confirmed
                                        immediately; a transfer is recorded on somebody&rsquo;s word and marked as
                                        received when the bank agrees. Both move the balance.</td>
                                </tr>
                                <tr>
                                    <td class="mono">Record a refund</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>Money genuinely handed back. Its own negative line; nothing is deleted.</td>
                                </tr>
                                <tr>
                                    <td class="mono">Correct</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>For a line that should never have been entered. Both lines stay, so it is
                                        visibly a correction rather than a refund.</td>
                                </tr>
                                <tr>
                                    <td class="mono">Ask for the deposit</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>Produces a payment link to send the guest. Clicking it twice gives the same
                                        link, not a second transaction. The payment records itself when it lands.</td>
                                </tr>
                                <tr>
                                    <td class="mono">Issue an invoice</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>Takes the next number in that property&rsquo;s own series. VAT and levy on
                                        their own lines. It cannot be changed afterwards.</td>
                                </tr>
                                <tr>
                                    <td class="mono">Credit it</td>
                                    <td><span class="who desk">Lodge desk</span></td>
                                    <td>The only way to put a wrong invoice right. Its own number, pointing at the
                                        one it corrects.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="note"><strong>Each property has its own invoice numbering.</strong> Their invoices
                        are their legal record, and our numbering is never interleaved into it. The run is gapless
                        by design &mdash; if an operator asks, that is deliberate and it is what an auditor looks
                        for.</div>
                </section>

                <section id="claims">
                    <h2>What we may say, and what we may not</h2>
                    <p class="lede">The same discipline as the flyers. <code>marketing/README.md</code> is the
                        source; this is the payments half of it.</p>

                    <div class="fields">
                        <table class="fields-table">
                            <thead>
                                <tr><th>Say this</th><th>Not this</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>&ldquo;A folio, payment records and invoices, running today.&rdquo;</td>
                                    <td>Anything about a live partner using it. Nobody is connected yet.</td>
                                </tr>
                                <tr>
                                    <td>&ldquo;We can take card payments online through a Namibian gateway.&rdquo;</td>
                                    <td>&ldquo;We take card payments.&rdquo; There is no merchant account yet.</td>
                                </tr>
                                <tr>
                                    <td>&ldquo;Real software on made-up data.&rdquo; (the demo)</td>
                                    <td>&ldquo;A pilot&rdquo;, or anything implying a customer.</td>
                                </tr>
                                <tr>
                                    <td>&ldquo;Commission is only on confirmed bookings.&rdquo;</td>
                                    <td>A commission percentage in print, until that is signed off.</td>
                                </tr>
                                <tr>
                                    <td>&ldquo;Three settlement models &mdash; you choose.&rdquo;</td>
                                    <td>Offering 0&nbsp;% as a self-service option. It needs an agreement.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="warn"><strong>Still undecided, so do not commit to it:</strong> exactly when we
                        count commission as earned (today: at confirmation, given back on cancellation, kept on a
                        late cancellation or a no-show), and payment terms under the agency model. If a partner
                        pushes, say it is being finalised rather than inventing an answer.</div>
                </section>
            </main>

            <footer>
                Money is written in one place in the code, and nothing here can be edited or deleted from a
                screen &mdash; a mistake is always a second line that references the first. If a number on a
                screen looks wrong, it is a reporting question, not a data-entry one: the folio total is stored
                from the payment lines and the two cannot drift apart. The full reasoning lives in
                <code>PAYMENTS.md</code>.
            </footer>
        </div>
    </div>
</x-filament-panels::page>
