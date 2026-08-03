<x-filament-panels::page>
    <div class="space-y-8 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            How to give a partner/OTA access to the public listings &amp; availability API
            (<code>api.namibway.com</code>) — separate from the booking connectors above, this is
            for external systems that want to <em>read</em> our listings, not partners whose own
            bookings we manage.
        </p>

        {{-- Workflow --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">1</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Create the API client</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Interfaces &rarr; Api Clients &rarr; New.</em> Name it after the partner/OTA
                    (e.g. "SafariBookings"), add a contact email.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">2</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Issue a token</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    Open the client &rarr; <em>Tokens</em> tab &rarr; <strong>"Issue token"</strong>.
                    The plaintext value is shown <strong>once</strong> — copy it immediately, it can't
                    be recovered afterwards.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">3</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Send it to the partner</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    No automated email yet — send the token yourself, together with the
                    <a href="{{ route('partner-api-guide') }}" class="text-primary-600 underline dark:text-primary-400" target="_blank" rel="noopener">
                        partner handout (PDF)
                    </a>
                    below.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">4</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Revoke access</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    Toggle <strong>"Is active"</strong> off to block every token immediately, or delete
                    a single token in the Tokens tab to revoke just that one.
                </p>
            </div>
        </div>

        {{-- Resources --}}
        <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">What to send the partner</h2>
            <div class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4 rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">Partner handout (PDF)</p>
                        <p class="text-xs text-gray-600 dark:text-gray-400">One-page quickstart: auth, base URL, the 3 endpoints, example requests.</p>
                    </div>
                    <a href="{{ route('partner-api-guide') }}" target="_blank" rel="noopener">
                        <x-filament::button icon="heroicon-o-arrow-down-tray" size="sm">Download</x-filament::button>
                    </a>
                </div>
                <div class="flex items-center justify-between gap-4 rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/5">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">Full interactive reference</p>
                        <p class="text-xs text-gray-600 dark:text-gray-400">Every endpoint, all parameters, try-it-out — generated from the actual API code (Scribe).</p>
                    </div>
                    <a href="{{ rtrim(config('scribe.base_url'), '/') }}/docs" target="_blank" rel="noopener">
                        <x-filament::button icon="heroicon-o-book-open" size="sm" color="gray">Open docs</x-filament::button>
                    </a>
                </div>
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            The API itself is read-only (listings + availability) — it never creates bookings.
            <code>booking_mode</code> in the availability response tells the partner whether we have
            live availability for a listing ("connector") or only take inquiries ("inquiry").
        </p>
    </div>
</x-filament-panels::page>
