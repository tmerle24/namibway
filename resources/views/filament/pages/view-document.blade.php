@php
    /** @var \App\Models\Document $document */
    $document = $this->getRecord();
    $comments = $this->comments();
@endphp

<x-filament-panels::page>
    <div class="nw-document">
        <x-filament::section>
            <x-slot name="heading">{{ $document->title }}</x-slot>

            <x-slot name="description">
                {{ $document->category?->name }} &middot; {{ $document->kind->getLabel() }}
            </x-slot>

            @if (filled($document->description))
                <p class="nw-document__description">{{ $document->description }}</p>
            @endif

            <dl class="nw-document__pairs">
                <div>
                    <dt>Filed by</dt>
                    <dd>{{ $document->author_name }}</dd>
                </div>
                <div>
                    <dt>Filed on</dt>
                    <dd>{{ $document->created_at?->isoFormat('D MMM YYYY') ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Last changed by</dt>
                    <dd>{{ $document->editor_name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Last changed</dt>
                    <dd>{{ $document->updated_at?->isoFormat('D MMM YYYY, HH:mm') ?? '—' }}</dd>
                </div>
                @if ($document->isFile())
                    <div>
                        <dt>File</dt>
                        <dd>{{ $document->downloadName() }}</dd>
                    </div>
                    <div>
                        <dt>Size</dt>
                        <dd>{{ $document->humanSize() ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        @if ($document->isPage())
            <x-filament::section>
                <x-slot name="heading">Content</x-slot>

                {{-- Written in the panel's own rich editor by signed-in staff, the
                     same trust model as a release note's body. --}}
                <div class="nw-document__body">
                    {!! $document->body !!}
                </div>
            </x-filament::section>
        @elseif ($document->isFile())
            <x-filament::section>
                <x-slot name="heading">The file</x-slot>

                <x-slot name="description">
                    Only reachable while signed in — documents are kept off the public media bucket.
                </x-slot>

                @if ($document->isImage())
                    <a href="{{ route('documents.download', $document) }}" target="_blank" rel="noopener">
                        <img
                            src="{{ route('documents.download', $document) }}"
                            alt="{{ $document->title }}"
                            class="nw-document__preview"
                        />
                    </a>
                @endif

                <div class="nw-document__actions">
                    <x-filament::button
                        tag="a"
                        href="{{ route('documents.download', $document) }}"
                        target="_blank"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        Open {{ $document->downloadName() }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Comments</x-slot>

            <x-slot name="description">
                Anyone on the team can add one. Each keeps its author and its date, so a decision
                made here can still be traced back a year later.
            </x-slot>

            @if ($comments->isEmpty())
                <p class="nw-document__empty">Nothing said about this yet.</p>
            @else
                <ul class="nw-document__comments">
                    @foreach ($comments as $comment)
                        <li class="nw-document__comment">
                            <p class="nw-document__meta">
                                {{ $comment->author_name }} &middot;
                                {{ $comment->created_at?->isoFormat('D MMM YYYY, HH:mm') }}
                            </p>
                            <p class="nw-document__text">{{ $comment->body }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <style>
            .nw-document {
                display: flex;
                flex-direction: column;
                gap: 1.5rem;
            }

            .nw-document__description {
                font-size: 0.9375rem;
                line-height: 1.5;
                color: rgb(39 39 42);
                white-space: pre-line;
            }

            .nw-document__pairs {
                margin-top: 1rem;
                display: grid;
                gap: 0.6rem;
                grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
            }

            .nw-document__pairs dt {
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: rgb(113 113 122);
            }

            .nw-document__pairs dd {
                margin-top: 0.15rem;
                font-size: 0.9375rem;
                font-weight: 600;
                color: rgb(24 24 27);
                overflow-wrap: anywhere;
            }

            .nw-document__body {
                font-size: 0.9375rem;
                line-height: 1.6;
                color: rgb(39 39 42);
            }

            .nw-document__body h2,
            .nw-document__body h3 {
                font-weight: 700;
                margin: 1.1rem 0 0.4rem;
            }

            .nw-document__body h2 { font-size: 1.125rem; }
            .nw-document__body h3 { font-size: 1rem; }

            .nw-document__body p { margin: 0.5rem 0; }

            .nw-document__body ul,
            .nw-document__body ol {
                margin: 0.5rem 0 0.5rem 1.25rem;
                list-style: revert;
            }

            .nw-document__body blockquote {
                margin: 0.75rem 0;
                padding-left: 0.75rem;
                border-left: 3px solid rgb(228 228 231);
                color: rgb(82 82 91);
            }

            .nw-document__body a {
                color: rgb(181 101 29);
                text-decoration: underline;
            }

            .nw-document__preview {
                max-width: 100%;
                max-height: 420px;
                border-radius: 0.5rem;
                object-fit: contain;
            }

            .nw-document__actions {
                margin-top: 1rem;
            }

            .nw-document__comments {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .nw-document__comment {
                padding: 0.6rem 0.75rem;
                border-radius: 0.5rem;
                border: 1px solid rgb(228 228 231);
                background-color: rgb(250 250 250);
            }

            .nw-document__meta {
                font-size: 0.6875rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: rgb(113 113 122);
            }

            .nw-document__text {
                margin-top: 0.2rem;
                font-size: 0.875rem;
                line-height: 1.45;
                color: rgb(39 39 42);
                white-space: pre-line;
            }

            .nw-document__empty {
                font-size: 0.8125rem;
                color: rgb(113 113 122);
            }

            .dark .nw-document__description,
            .dark .nw-document__pairs dd,
            .dark .nw-document__body,
            .dark .nw-document__text {
                color: rgb(244 244 245);
            }

            .dark .nw-document__body blockquote {
                border-color: rgb(63 63 70);
                color: rgb(212 212 216);
            }

            .dark .nw-document__comment {
                border-color: rgb(63 63 70);
                background-color: rgb(39 39 42);
            }
        </style>
    </div>
</x-filament-panels::page>
