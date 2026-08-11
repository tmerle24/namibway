<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Before you print a batch</x-slot>

        <div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
            <p class="max-w-3xl">
                Take the <strong class="font-medium text-gray-950 dark:text-white">print</strong> file to a print
                shop and the <strong class="font-medium text-gray-950 dark:text-white">A4</strong> file to email.
                The print file is deliberately larger than A4 — that overhang is the bleed, and it gets trimmed off.
                It is not a mistake, and it should not be "fixed" by scaling the page down to A4, which would leave a
                white edge on every copy.
            </p>
            <p class="max-w-3xl">
                A few things are still open on purpose and are marked <code>EDIT:</code> in the source: no phone
                number is printed anywhere, the partner flyer names no commission rate, and the website price is a
                proposal. Check those against what we currently say before ordering anything in quantity.
            </p>
        </div>
    </x-filament::section>

    @foreach ($material as $item)
        <x-filament::section>
            <x-slot name="heading">{{ $item['title'] }}</x-slot>
            <x-slot name="description">{{ $item['audience'] }}</x-slot>

            <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                {{ $item['description'] }}
            </p>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                @foreach ($item['files'] as $file)
                    <div @class([
                        'rounded-xl p-4 ring-1',
                        'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => $file['available'],
                        'bg-transparent ring-dashed ring-gray-300 dark:ring-gray-700' => ! $file['available'],
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="text-sm font-medium text-gray-950 dark:text-white">
                                {{ $file['label'] }}
                            </div>

                            @if ($file['size'])
                                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                    {{ $file['size'] }}
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $file['note'] }}
                        </p>

                        <div class="mt-4">
                            @if ($file['available'])
                                <x-filament::button
                                    tag="a"
                                    href="{{ route('marketing-material.download', ['item' => $item['key'], 'variant' => $file['variant']]) }}"
                                    icon="heroicon-m-arrow-down-tray"
                                    :color="$file['variant'] === 'print' ? 'primary' : 'gray'"
                                    size="sm"
                                >
                                    Download PDF
                                </x-filament::button>
                            @else
                                <p class="text-xs text-danger-600 dark:text-danger-400">
                                    Not built. Run <code>node marketing/build-flyer.mjs</code> and commit the result.
                                </p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Source: <code>{{ $item['source'] }}</code>
            </p>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
