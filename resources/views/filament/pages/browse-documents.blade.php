@php
    $trail = $this->breadcrumbTrail();
    $folders = $this->folders();
    $here = $this->currentFolder();
@endphp

<x-filament-panels::page>
    <div class="nw-files">
        {{-- Where you are. The whole trail is clickable, so going up is one tap
             from any depth rather than a walk back through each level. --}}
        <nav class="nw-files__crumbs" aria-label="Folder path">
            <button type="button" wire:click="openFolder(null)" @class(['nw-files__crumb', 'nw-files__crumb--current' => $trail === []])>
                <x-filament::icon icon="heroicon-o-home" class="nw-files__crumb-icon" />
                Documents
            </button>

            @foreach ($trail as $step)
                <span class="nw-files__sep" aria-hidden="true">/</span>
                <button
                    type="button"
                    wire:click="openFolder({{ $step->id }})"
                    @class(['nw-files__crumb', 'nw-files__crumb--current' => $loop->last])
                >
                    {{ $step->name }}
                </button>
            @endforeach
        </nav>

        @if (filled($here?->description))
            <p class="nw-files__note">{{ $here->description }}</p>
        @endif

        <x-filament::section>
            <x-slot name="heading">Folders</x-slot>

            <x-slot name="description">
                {{ $here === null
                    ? 'The top level. A new folder is created here.'
                    : 'Inside ' . $here->name . '. A new folder is created here.' }}
            </x-slot>

            @if ($folders->isEmpty())
                <p class="nw-files__empty">No folders here — use “Add folder” to make one.</p>
            @else
                <ul class="nw-files__grid">
                    @foreach ($folders as $folder)
                        <li class="nw-files__folder">
                            <button type="button" wire:click="openFolder({{ $folder->id }})" class="nw-files__open">
                                <x-filament::icon :icon="$folder->icon ?: 'heroicon-o-folder'" class="nw-files__icon" />
                                <span class="nw-files__text">
                                    <span class="nw-files__name">{{ $folder->name }}</span>
                                    <span class="nw-files__count">
                                        {{ trans_choice('{0}empty|{1}:count document|[2,*]:count documents', $folder->documents_count, ['count' => $folder->documents_count]) }}@if ($folder->children_count > 0), {{ trans_choice('{1}:count folder|[2,*]:count folders', $folder->children_count, ['count' => $folder->children_count]) }}@endif
                                    </span>
                                </span>
                            </button>

                            <div class="nw-files__folder-actions">
                                {{ ($this->renameFolderAction)(['folder' => $folder->id]) }}
                                {{ ($this->moveFolderAction)(['folder' => $folder->id]) }}
                                {{ ($this->deleteFolderAction)(['folder' => $folder->id]) }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        {{-- The documents of this one folder, with Filament's own table doing
             the search, the sorting and the row actions. --}}
        {{ $this->table }}

        <style>
            .nw-files {
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
            }

            .nw-files__crumbs {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.35rem;
                font-size: 0.875rem;
            }

            .nw-files__crumb {
                display: inline-flex;
                align-items: center;
                gap: 0.3rem;
                padding: 0.2rem 0.5rem;
                border-radius: 0.375rem;
                color: rgb(113 113 122);
                font-weight: 600;
            }

            .nw-files__crumb:hover {
                background-color: rgb(244 244 245);
                color: rgb(39 39 42);
            }

            .nw-files__crumb--current {
                color: rgb(24 24 27);
            }

            .nw-files__crumb-icon {
                width: 1rem;
                height: 1rem;
            }

            .nw-files__sep {
                color: rgb(161 161 170);
            }

            .nw-files__note {
                font-size: 0.875rem;
                color: rgb(82 82 91);
            }

            .nw-files__grid {
                display: grid;
                gap: 0.6rem;
                grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
            }

            .nw-files__folder {
                display: flex;
                align-items: center;
                gap: 0.4rem;
                padding: 0.6rem 0.65rem;
                border: 1px solid rgb(228 228 231);
                border-radius: 0.6rem;
                background-color: rgb(250 250 250);
            }

            .nw-files__folder:hover {
                border-color: rgb(181 101 29);
            }

            .nw-files__open {
                display: flex;
                align-items: center;
                gap: 0.6rem;
                flex: 1 1 auto;
                min-width: 0;
                text-align: left;
            }

            .nw-files__icon {
                width: 1.5rem;
                height: 1.5rem;
                flex: none;
                color: rgb(181 101 29);
            }

            .nw-files__text {
                display: flex;
                flex-direction: column;
                min-width: 0;
                gap: 0.1rem;
            }

            .nw-files__name {
                font-size: 0.9375rem;
                font-weight: 600;
                color: rgb(24 24 27);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .nw-files__count {
                font-size: 0.75rem;
                color: rgb(113 113 122);
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .nw-files__folder-actions {
                display: flex;
                align-items: center;
                gap: 0.15rem;
                flex: none;
            }

            .nw-files__empty {
                font-size: 0.8125rem;
                color: rgb(113 113 122);
            }

            .dark .nw-files__crumb--current,
            .dark .nw-files__name {
                color: rgb(244 244 245);
            }

            .dark .nw-files__crumb:hover {
                background-color: rgb(39 39 42);
                color: rgb(244 244 245);
            }

            .dark .nw-files__folder {
                border-color: rgb(63 63 70);
                background-color: rgb(39 39 42);
            }

            .dark .nw-files__note {
                color: rgb(212 212 216);
            }
        </style>
    </div>
</x-filament-panels::page>
