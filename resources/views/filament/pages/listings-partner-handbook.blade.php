<x-filament-panels::page>
    <div class="space-y-8 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Working guide for completing listing profiles and contacting the businesses behind them —
            written for whoever is doing that research day to day (no coding background assumed).
        </p>

        {{-- The two jobs --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">1</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Complete a listing profile</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Data Enrichment</em> (left menu). Raise each listing's completion score by filling in
                    what's missing, using the business's own website/socials or a phone call.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">2</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Contact the owners</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Partners</em> table &rarr; <strong>"Invite Owner"</strong>. Sends a passwordless link
                    letting them claim and edit their own listing.
                </p>
            </div>
        </div>

        {{-- Job 1 detail --}}
        <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Job 1 — Complete a listing profile</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Every listing has a completion score out of 100, made up of these weighted fields:
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1.5 pr-4">Field</th>
                            <th class="py-1.5 pr-4">Weight</th>
                            <th class="py-1.5">Where to find it</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        <tr>
                            <td class="py-1.5 pr-4">Description (2–4 sentences, English)</td>
                            <td class="py-1.5 pr-4">20%</td>
                            <td class="py-1.5">Their site's "About" page — write it in your own words, don't copy-paste</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Photos (1 hero + gallery)</td>
                            <td class="py-1.5 pr-4">20%</td>
                            <td class="py-1.5">Their own site/social media only — see photo rights note below</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Website</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Search the business name + "Namibia"</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Email</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Their contact page, or ask by phone</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Phone</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Usually already on file</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Address</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Their site / Google Maps</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">GPS pin</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Google Maps &rarr; right-click the pin &rarr; coordinates</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Facilities / activities on offer</td>
                            <td class="py-1.5 pr-4">10%</td>
                            <td class="py-1.5">Their site, brochures</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ol class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                <li><strong>1.</strong> In <em>Data Enrichment</em>, sort by completion score to find the lowest first.</li>
                <li><strong>2.</strong> Click a row — it opens in tabs: <em>Basic, Contact, Address, Description, Photos, Metadata</em>.</li>
                <li><strong>3.</strong> Research the business and fill in whichever tab matches what you found.</li>
                <li><strong>4.</strong> Save — the score updates automatically.</li>
            </ol>

            <div class="mt-4 rounded-lg bg-warning-50 px-4 py-3 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                <strong>Photo rights:</strong> only use a photo the business published themselves (their own site
                or official social page), or that they've explicitly OK'd. Never pull from Google Images,
                TripAdvisor, or other travelers' posts. If in doubt, leave it blank rather than guess.
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                <strong>Definition of "done":</strong> score of 90% or higher. Not every field applies to every
                listing (e.g. "activities on offer" for a restaurant) — 100% isn't the bar.
            </p>
        </div>

        {{-- Job 2 detail --}}
        <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Job 2 — Contact the owners</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Most listed businesses don't yet know they're on NamibWay. Goal: let them know, invite them to
                claim their page, and note their booking system if they have one.
            </p>

            <div class="mt-4 space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <p>
                    <strong>Have an email on file?</strong> Open the <em>Partner</em> record &rarr;
                    <strong>"Invite Owner"</strong>. It sends a personal link so they can log in without a
                    password and edit their own page. The Partners table tracks who's been invited, replied,
                    or declined — no separate spreadsheet needed.
                </p>
                <p>
                    <strong>No email on file?</strong> This is most of the outreach work. Find a contact channel
                    first (site contact form, listed phone number, Facebook page), then either send the claim
                    link or — if they're not comfortable online — offer to fill in confirmed details yourself
                    over the phone.
                </p>
                <p>
                    <strong>No response?</strong> For accommodation, activities and vehicle rentals, a follow-up
                    call after 5–7 days is worth it — these matter most to trip quality. For restaurants, one
                    email is usually enough.
                </p>
                <p>
                    <strong>Booking system mentioned?</strong> Note it on the Partner's <em>Connector Type</em>
                    field (ResRequest / NightsBridge / hopeCloud / Wetu / NWR / Manual) — see the
                    <a href="{{ \App\Filament\Pages\BookingConnectorGuide::getUrl() }}" class="text-primary-600 dark:text-primary-400">Booking Connector Guide</a>
                    for what happens next. No API credentials to configure yourself; that's a separate technical
                    step once we know which system is involved.
                </p>
            </div>

            <div class="mt-4 rounded-lg bg-danger-50 px-4 py-3 text-xs text-danger-800 dark:bg-danger-500/10 dark:text-danger-300">
                If a partner asks about money, contracts, or commission — don't negotiate or promise anything.
                Note the question and pass it along.
            </div>
        </div>

        {{-- Priorities --}}
        <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">What to work on first</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Accommodation and activities are what the itinerary engine actually builds trips around —
                prioritize those over the (larger) restaurant list.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="py-1.5 pr-4">Type</th>
                            <th class="py-1.5 pr-4">Count</th>
                            <th class="py-1.5">Priority</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        <tr>
                            <td class="py-1.5 pr-4">Accommodation</td>
                            <td class="py-1.5 pr-4">37</td>
                            <td class="py-1.5"><x-filament::badge color="danger">Do first</x-filament::badge></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Activities &amp; tours</td>
                            <td class="py-1.5 pr-4">69</td>
                            <td class="py-1.5"><x-filament::badge color="danger">Do first</x-filament::badge></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Vehicle rental</td>
                            <td class="py-1.5 pr-4">7</td>
                            <td class="py-1.5"><x-filament::badge color="danger">Do first</x-filament::badge></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 pr-4">Restaurants</td>
                            <td class="py-1.5 pr-4">163</td>
                            <td class="py-1.5"><x-filament::badge color="success">Steady, ongoing</x-filament::badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Reporting & rules --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Weekly check-in</h3>
                <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400 list-disc list-inside">
                    <li>Listings brought to 90%+ this week</li>
                    <li>Owners invited / reached by phone</li>
                    <li>Claims and declines (with reason, if given)</li>
                    <li>Anything you got stuck on, or any money/contract question raised</li>
                </ul>
            </div>
            <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">What not to do</h3>
                <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400 list-disc list-inside">
                    <li>Don't unpublish, delete, or merge listings — flag duplicates instead</li>
                    <li>Don't enter or discuss payment details</li>
                    <li>Don't use a photo without confirmed rights</li>
                    <li>Don't promise commission rates or contract terms</li>
                </ul>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Questions any time — this covers the day-to-day; anything unusual is always fine to ask about
            rather than guess.
        </p>
    </div>
</x-filament-panels::page>
