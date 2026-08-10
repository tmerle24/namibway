<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. Upload</x-slot>
        <x-slot name="description">
            Every import starts as a dry run. Check the file, read the preview, then import — nothing is
            written before that.
        </x-slot>

        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            {{ $this->checkAction }}
            {{ $this->importAction }}
            {{ $this->reportAction }}
        </div>
    </x-filament::section>

    @if ($preview)
        @if ($preview['blocked'])
            <x-filament::section>
                <x-slot name="heading">The file could not be read</x-slot>

                <ul class="list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($preview['file_errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">2. What this file would do</x-slot>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    @foreach ([
                        ['New listings', $preview['new'], 'text-success-600 dark:text-success-400'],
                        ['Updated', $preview['updated'], 'text-primary-600 dark:text-primary-400'],
                        ['Field changes', $preview['changes'], 'text-gray-950 dark:text-white'],
                        ['Unchanged', $preview['unchanged'], 'text-gray-500 dark:text-gray-400'],
                        ['Rows with errors', $preview['invalid'], 'text-danger-600 dark:text-danger-400'],
                    ] as [$label, $value, $tone])
                        <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                            <div class="text-2xl font-semibold {{ $tone }}">{{ $value }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>

                @if ($preview['ignored_headers'])
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        Ignored unknown columns: {{ implode(', ', $preview['ignored_headers']) }}
                    </p>
                @endif
            </x-filament::section>

            @if ($preview['errors'])
                <x-filament::section>
                    <x-slot name="heading">Rows with errors</x-slot>
                    <x-slot name="description">
                        These rows are not imported. Fix them in the workbook and upload it again.
                    </x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-start text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="w-16 py-2 text-start font-medium">Row</th>
                                    <th class="w-64 py-2 text-start font-medium">Listing</th>
                                    <th class="py-2 text-start font-medium">Problem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($preview['errors'] as $error)
                                    <tr>
                                        <td class="py-2 tabular-nums">{{ $error['line'] }}</td>
                                        <td class="py-2">{{ $error['name'] }}</td>
                                        <td class="py-2 text-danger-600 dark:text-danger-400">{{ $error['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            @if ($preview['rows'])
                <x-filament::section collapsible>
                    <x-slot name="heading">Changes in detail</x-slot>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="w-16 py-2 text-start font-medium">Row</th>
                                    <th class="w-64 py-2 text-start font-medium">Listing</th>
                                    <th class="w-40 py-2 text-start font-medium">Field</th>
                                    <th class="py-2 text-start font-medium">Before</th>
                                    <th class="py-2 text-start font-medium">After</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($preview['rows'] as $row)
                                    @if ($row['is_new'])
                                        <tr>
                                            <td class="py-2 tabular-nums">{{ $row['line'] }}</td>
                                            <td class="py-2 font-medium">{{ $row['name'] }}</td>
                                            <td class="py-2 text-success-600 dark:text-success-400" colspan="3">
                                                new listing
                                            </td>
                                        </tr>
                                    @else
                                        @foreach ($row['changes'] as $change)
                                            <tr>
                                                <td class="py-2 tabular-nums">{{ $loop->first ? $row['line'] : '' }}</td>
                                                <td class="py-2">{{ $loop->first ? $row['name'] : '' }}</td>
                                                <td class="py-2 font-mono text-xs">{{ $change['column'] }}</td>
                                                <td class="py-2 text-gray-500 dark:text-gray-400">
                                                    {{ \Illuminate\Support\Str::limit($change['old'] ?? '—', 70) }}
                                                </td>
                                                <td class="py-2">
                                                    {{ \Illuminate\Support\Str::limit($change['new'] ?? '—', 70) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($preview['more_rows'])
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                            … and {{ $preview['more_rows'] }} more rows. Use “Download report” for the full list.
                        </p>
                    @endif
                </x-filament::section>
            @endif

            @if ($preview['warnings'])
                <x-filament::section collapsible collapsed>
                    <x-slot name="heading">Notes ({{ count($preview['warnings']) }})</x-slot>
                    <x-slot name="description">Not blocking — worth a look.</x-slot>

                    <ul class="space-y-1 text-sm text-gray-500 dark:text-gray-400">
                        @foreach ($preview['warnings'] as $warning)
                            <li>
                                <span class="tabular-nums">Row {{ $warning['line'] }}</span>
                                — {{ $warning['name'] }}: {{ $warning['message'] }}
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endif
        @endif
    @endif
</x-filament-panels::page>
