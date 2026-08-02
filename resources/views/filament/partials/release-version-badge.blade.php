@php
    $release = \App\Services\ReleaseVersion::current();
@endphp

@if ($release['version'])
    <a
        href="{{ \App\Filament\Pages\ReleaseNotes::getUrl() }}"
        title="Release Notes — {{ $release['version'] }}"
        class="hidden items-center rounded-lg px-2 py-1 font-mono text-xs font-medium text-gray-400 transition hover:bg-gray-50 hover:text-gray-600 sm:flex dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-300"
    >
        {{ $release['version'] }}
    </a>
@endif
