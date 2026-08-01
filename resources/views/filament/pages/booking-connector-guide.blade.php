<x-filament-panels::page>
    <div class="space-y-8 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Which fields need to be filled in where so a listing gets real availability &amp; reservations
            via ResConnect, NightsBridge, hopeCloud, Wetu, or NWR — partner by partner, listing by listing.
        </p>

        {{-- Workflow --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">1</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Set up the partner account</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Partner &rarr; Booking Connector.</em> Choose the connector type, enter API credentials.
                    Applies to all of this partner's listings. Easiest done directly via the wizard on the
                    partner's first listing page.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">2</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Connect the listing</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Listing &rarr; Booking system / API.</em> Enter this specific listing's property code —
                    a partner can have several properties, each with its own code.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">3</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Test</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    Click <strong>"Test connector"</strong> in the listings table. Checks
                    <code>checkAvailability()</code> live and reports success or the failure reason — without
                    creating a real reservation.
                </p>
            </div>
        </div>

        {{-- Overview --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5">Connector</th>
                        <th class="px-4 py-2.5">Partner fields</th>
                        <th class="px-4 py-2.5">Listing field</th>
                        <th class="px-4 py-2.5">Live availability?</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">ResConnect</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Yes</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">NightsBridge</td>
                        <td class="px-4 py-2.5 font-mono text-xs">bbid, api_key, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Yes</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">hopeCloud</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key, account_id, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Yes</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">Wetu</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key</td>
                        <td class="px-4 py-2.5 font-mono text-xs">wetu_id (own field!)</td>
                        <td class="px-4 py-2.5">No — content only</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">NWR / Manual</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">—</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">—</td>
                        <td class="px-4 py-2.5">No — manual review</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Connector detail cards --}}
        @php
            $connectors = [
                [
                    'name' => 'ResConnect (ResRequest)',
                    'badge' => ['label' => 'Live bookable', 'color' => 'success'],
                    'intro' => 'The market leader among Southern African/Namibian lodges. Free API called ResConnect.',
                    'rows' => [
                        ['connector_type', 'Partner', true, 'Select "ResConnect (ResRequest)"'],
                        ['api_key', 'Partner', true, 'API key from the ResRequest partner portal'],
                        ['base_url', 'Partner', false, 'Only set if different from api.resrequest.com'],
                        ['connector_property_code', 'Listing', true, "This listing's ResRequest property code"],
                    ],
                    'note' => null,
                ],
                [
                    'name' => 'NightsBridge',
                    'badge' => ['label' => 'Live bookable', 'color' => 'success'],
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
                    'name' => 'hopeCloud',
                    'badge' => ['label' => 'Live bookable', 'color' => 'success'],
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
                    'name' => 'Wetu',
                    'badge' => ['label' => 'Content only', 'color' => 'gray'],
                    'intro' => 'Not a booking connector but a content connector — imports name, description, highlights, region & coordinates. No availability check.',
                    'rows' => [
                        ['connector_type', 'Partner', true, 'Select "Wetu (content only)"'],
                        ['api_key', 'Partner', true, 'API key from Wetu'],
                        ['wetu_id', 'Listing', true, "This listing's Wetu property ID"],
                    ],
                    'note' => 'Wetu uses wetu_id directly on the listing — not the "Booking system / API" field connector_property_code. Afterwards, use "Import from Wetu" in the listings table.',
                ],
                [
                    'name' => 'NWR & Manual',
                    'badge' => ['label' => 'No API access', 'color' => 'danger'],
                    'intro' => 'Namibia Wildlife Resorts (Etosha, Sossusvlei, Fish River Canyon) has no API — their system is notorious for showing "fully booked" when it isn\'t. Every request goes to the team as nwr_pending for manual review. "Manual" is the default for any partner without a connector: a plain email notification, no live check.',
                    'rows' => [
                        ['connector_type', 'Partner', true, 'Select "NWR — Concierge (manual)", or leave blank for "Manual"'],
                    ],
                    'note' => 'No API key, no property code needed.',
                ],
            ];
        @endphp

        <div class="space-y-6">
            @foreach ($connectors as $connector)
                <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $connector['name'] }}</h2>
                        <x-filament::badge :color="$connector['badge']['color']">
                            {{ $connector['badge']['label'] }}
                        </x-filament::badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $connector['intro'] }}</p>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="py-1.5 pr-4">Field</th>
                                    <th class="py-1.5 pr-4">Where</th>
                                    <th class="py-1.5 pr-4">Required</th>
                                    <th class="py-1.5">Value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($connector['rows'] as [$field, $where, $required, $value])
                                    <tr>
                                        <td class="py-1.5 pr-4 font-mono text-xs">{{ $field }}</td>
                                        <td class="py-1.5 pr-4">
                                            <x-filament::badge :color="$where === 'Listing' ? 'primary' : 'gray'" size="xs">
                                                {{ $where }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="py-1.5 pr-4">
                                            @if ($required)
                                                <span class="font-medium text-danger-600 dark:text-danger-400">Required</span>
                                            @else
                                                <span class="text-gray-500 dark:text-gray-400">Optional</span>
                                            @endif
                                        </td>
                                        <td class="py-1.5">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($connector['note'])
                        <div class="mt-4 rounded-lg bg-warning-50 px-4 py-3 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                            {{ $connector['note'] }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            All credentials in <code>connector_config</code> are stored encrypted in the database.
            "Test connector" (listings table) checks a test date 30 days out and reports the result
            as a notification — without creating a real reservation.
        </p>
    </div>
</x-filament-panels::page>
