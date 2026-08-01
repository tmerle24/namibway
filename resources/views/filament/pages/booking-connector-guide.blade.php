<x-filament-panels::page>
    <div class="space-y-8 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Welche Felder wo eingetragen werden müssen, damit ein Listing echte Verfügbarkeit &amp; Reservierungen
            über ResConnect, NightsBridge, hopeCloud, Wetu oder NWR bekommt — Partner für Partner, Listing für Listing.
        </p>

        {{-- Workflow --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">1</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Partner-Account einrichten</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Partner &rarr; Booking Connector.</em> Connector-Typ wählen, API-Zugangsdaten eintragen.
                    Gilt für alle Listings dieses Partners. Läuft am einfachsten direkt über den Wizard auf der
                    ersten Listing-Seite dieses Partners.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">2</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Listing anschließen</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    <em>Listing &rarr; Booking system / API.</em> Property Code dieses konkreten Listings
                    eintragen — ein Partner kann mehrere Properties haben, jede mit eigenem Code.
                </p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <span class="text-2xl font-semibold text-primary-600 dark:text-primary-400">3</span>
                <h3 class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">Testen</h3>
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                    In der Listings-Tabelle auf <strong>„Test connector“</strong> klicken. Prüft
                    <code>checkAvailability()</code> live und meldet Erfolg oder Fehlerursache — ohne eine echte
                    Reservierung anzulegen.
                </p>
            </div>
        </div>

        {{-- Overview --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5">Connector</th>
                        <th class="px-4 py-2.5">Partner-Felder</th>
                        <th class="px-4 py-2.5">Listing-Feld</th>
                        <th class="px-4 py-2.5">Live-Verfügbarkeit?</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">ResConnect</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Ja</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">NightsBridge</td>
                        <td class="px-4 py-2.5 font-mono text-xs">bbid, api_key, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Ja</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">hopeCloud</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key, account_id, base_url (opt.)</td>
                        <td class="px-4 py-2.5 font-mono text-xs">connector_property_code</td>
                        <td class="px-4 py-2.5">Ja</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">Wetu</td>
                        <td class="px-4 py-2.5 font-mono text-xs">api_key</td>
                        <td class="px-4 py-2.5 font-mono text-xs">wetu_id (eigenes Feld!)</td>
                        <td class="px-4 py-2.5">Nein — nur Inhalte</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2.5 font-semibold text-gray-950 dark:text-white">NWR / Manuell</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">—</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400">—</td>
                        <td class="px-4 py-2.5">Nein — manuelle Prüfung</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Connector detail cards --}}
        @php
            $connectors = [
                [
                    'name' => 'ResConnect (ResRequest)',
                    'badge' => ['label' => 'Live buchbar', 'color' => 'success'],
                    'intro' => 'Der Marktführer bei südafrikanischen/namibischen Lodges. Kostenlose API namens ResConnect.',
                    'rows' => [
                        ['connector_type', 'Partner', true, '„ResConnect (ResRequest)“ auswählen'],
                        ['api_key', 'Partner', true, 'API-Key aus dem ResRequest-Partnerportal'],
                        ['base_url', 'Partner', false, 'Nur setzen, falls abweichend von api.resrequest.com'],
                        ['connector_property_code', 'Listing', true, 'ResRequest Property Code dieses Listings'],
                    ],
                    'note' => null,
                ],
                [
                    'name' => 'NightsBridge',
                    'badge' => ['label' => 'Live buchbar', 'color' => 'success'],
                    'intro' => 'Channel-Manager, verbreitet bei kleineren Lodges & Gästehäusern. Authentifizierung per Basic Auth mit bbid + api_key.',
                    'rows' => [
                        ['connector_type', 'Partner', true, '„NightsBridge“ auswählen'],
                        ['bbid', 'Partner', true, 'Booking-Bureau-ID aus NightsBridge'],
                        ['api_key', 'Partner', true, 'API-Key aus NightsBridge'],
                        ['base_url', 'Partner', false, 'Nur setzen, falls abweichend vom Standard'],
                        ['connector_property_code', 'Listing', true, 'Dieselbe bbid hier noch einmal eintragen'],
                    ],
                    'note' => 'NightsBridge bindet eine bbid an genau eine Property — die Listing-Property-Code ist also derselbe Wert wie die bbid am Partner, kein zweiter Code. Betreut ein Partner mehrere Properties, hat jede ihre eigene bbid+api_key-Kombination und braucht also einen eigenen Partner-Eintrag.',
                ],
                [
                    'name' => 'hopeCloud',
                    'badge' => ['label' => 'Live buchbar', 'color' => 'success'],
                    'intro' => 'Namibia-spezifisch — verarbeitet automatisch die Tourism Levy und meldet für NTB/HAN.',
                    'rows' => [
                        ['connector_type', 'Partner', true, '„hopeCloud“ auswählen'],
                        ['api_key', 'Partner', true, 'API-Key aus hopeCloud'],
                        ['account_id', 'Partner', true, 'Account-ID aus hopeCloud'],
                        ['base_url', 'Partner', false, 'Nur setzen, falls abweichend vom Standard'],
                        ['connector_property_code', 'Listing', true, 'hopeCloud Property-/Unit-ID dieses Listings'],
                    ],
                    'note' => null,
                ],
                [
                    'name' => 'Wetu',
                    'badge' => ['label' => 'Nur Inhalte', 'color' => 'gray'],
                    'intro' => 'Kein Buchungs-, sondern ein Content-Connector — importiert Name, Beschreibung, Highlights, Region & Koordinaten. Keine Verfügbarkeitsprüfung.',
                    'rows' => [
                        ['connector_type', 'Partner', true, '„Wetu (content only)“ auswählen'],
                        ['api_key', 'Partner', true, 'API-Key aus Wetu'],
                        ['wetu_id', 'Listing', true, 'Wetu Property-ID dieses Listings'],
                    ],
                    'note' => 'Wetu nutzt wetu_id direkt am Listing — nicht das „Booking system / API“-Feld connector_property_code. Danach in der Listing-Tabelle „Import from Wetu“ nutzen.',
                ],
                [
                    'name' => 'NWR & Manuell',
                    'badge' => ['label' => 'Kein API-Zugang', 'color' => 'danger'],
                    'intro' => 'Namibia Wildlife Resorts (Etosha, Sossusvlei, Fish River Canyon) hat keine API — deren System zeigt notorisch „ausgebucht“, obwohl frei ist. Jede Anfrage geht als nwr_pending ans Team zur manuellen Prüfung. „Manuell“ ist der Default für alle Partner ohne Connector: einfache E-Mail-Benachrichtigung, keine Live-Prüfung.',
                    'rows' => [
                        ['connector_type', 'Partner', true, '„NWR — Concierge (manual)“ bzw. leer lassen für „Manual“'],
                    ],
                    'note' => 'Kein API-Key, kein Property Code nötig.',
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
                                    <th class="py-1.5 pr-4">Feld</th>
                                    <th class="py-1.5 pr-4">Wo</th>
                                    <th class="py-1.5 pr-4">Pflicht</th>
                                    <th class="py-1.5">Wert</th>
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
                                                <span class="font-medium text-danger-600 dark:text-danger-400">Pflicht</span>
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
            Alle Zugangsdaten in <code>connector_config</code> werden verschlüsselt in der Datenbank gespeichert.
            „Test connector“ (Listings-Tabelle) prüft ein Testdatum 30 Tage in der Zukunft und meldet das Ergebnis
            als Notification — ohne dabei eine echte Reservierung anzulegen.
        </p>
    </div>
</x-filament-panels::page>
