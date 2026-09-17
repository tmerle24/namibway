<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">1. Upload</x-slot>
        <x-slot name="description">
            Check first: the preview shows which partner, listing and website the package matched and every
            field it would change. Nothing is written before you press Import.
        </x-slot>

        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            {{ $this->checkAction }}
            {{ $this->importAction }}
        </div>
    </x-filament::section>

    @if ($siteUrl)
        <x-filament::section>
            <x-slot name="heading">Imported</x-slot>
            <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="text-primary-600 underline dark:text-primary-400">
                Open the website
            </a>
        </x-filament::section>
    @endif

    @if ($preview)
        @if ($preview['errors'])
            <x-filament::section>
                <x-slot name="heading">Problems — nothing can be imported until these are fixed</x-slot>

                <ul class="list-disc space-y-1 ps-5 text-sm text-danger-600 dark:text-danger-400">
                    @foreach ($preview['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">2. What this package {{ $preview['written'] ? 'did' : 'would do' }}</x-slot>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($preview['entities'] as $entity)
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $entity['kind'] }}</div>
                        <div class="font-semibold {{ $entity['action'] === 'create' ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white' }}">
                            {{ $entity['action'] }}{{ $entity['id'] ? ' #'.$entity['id'] : '' }}
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $entity['label'] }}</div>
                    </div>
                @endforeach
                @if ($preview['listings_new'] || $preview['listings_updated'])
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Listings</div>
                        <div class="font-semibold {{ $preview['listings_new'] ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white' }}">
                            {{ $preview['listings_new'] }} new · {{ $preview['listings_updated'] }} updated
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">from listings.csv</div>
                    </div>
                @endif

                <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Media</div>
                    <div class="font-semibold text-gray-950 dark:text-white">
                        {{ $preview['images_new'] }} new · {{ $preview['images_updated'] }} existing pictures
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $preview['videos'] }} video(s)</div>
                </div>
            </div>
        </x-filament::section>

        @php $changes = collect($preview['entities'])->filter(fn ($e) => $e['changes'] !== []); @endphp
        @if ($changes->isNotEmpty())
            <x-filament::section collapsible>
                <x-slot name="heading">Field changes</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="w-28 py-2 text-start font-medium">Record</th>
                                <th class="w-40 py-2 text-start font-medium">Field</th>
                                <th class="py-2 text-start font-medium">Before</th>
                                <th class="py-2 text-start font-medium">After</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($changes as $entity)
                                @foreach ($entity['changes'] as $change)
                                    <tr>
                                        <td class="py-2">{{ $loop->first ? $entity['kind'] : '' }}</td>
                                        <td class="py-2 font-mono text-xs">{{ $change['field'] }}</td>
                                        <td class="py-2 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($change['old'] ?: '—', 70) }}</td>
                                        <td class="py-2">{{ \Illuminate\Support\Str::limit($change['new'] ?: '—', 70) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($preview['blocks'])
            <x-filament::section collapsible>
                <x-slot name="heading">Bands on the page, in order</x-slot>

                <ol class="list-decimal space-y-1 ps-5 text-sm">
                    @foreach ($preview['blocks'] as $block)
                        <li>
                            <span class="font-medium">{{ $block['label'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">— {{ $block['action'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif

        @foreach ($preview['pages'] ?? [] as $subpage)
            <x-filament::section collapsible collapsed>
                <x-slot name="heading">Page /{{ $subpage['slug'] }} - {{ $subpage['title'] }} ({{ $subpage['action'] }})</x-slot>

                <ol class="list-decimal space-y-1 ps-5 text-sm">
                    @foreach ($subpage['blocks'] as $block)
                        <li>
                            <span class="font-medium">{{ $block['label'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">- {{ $block['action'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endforeach

        @if ($preview['warnings'])
            <x-filament::section collapsible>
                <x-slot name="heading">Notes ({{ count($preview['warnings']) }})</x-slot>

                <ul class="space-y-1 text-sm text-gray-500 dark:text-gray-400">
                    @foreach ($preview['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
