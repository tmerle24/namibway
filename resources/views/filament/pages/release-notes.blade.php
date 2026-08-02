<x-filament-panels::page>
    <div class="nwrn">
        <style>
            .nwrn {
                --ink: #2A2317; --ink-soft: #6B6350; --line: #E4D9BE; --paper-2: #F5F0E3;
                --accent: #2C3E5C; --accent-soft: #DCE2EA; --gold: #B9812E;
                color: var(--ink);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
                line-height: 1.55;
                max-width: 48rem;
            }
            .dark .nwrn {
                --ink: #EDE6D6; --ink-soft: #B3A98E; --line: #3B3526; --paper-2: #242019;
                --accent: #8FA6CC; --accent-soft: #2A3345; --gold: #D9A54F;
            }
            .nwrn .eyebrow {
                font-size: 12px; letter-spacing: .11em; text-transform: uppercase;
                color: var(--gold); font-weight: 700; margin: 0 0 8px;
            }
            .nwrn h1 {
                font-family: Georgia, "Iowan Old Style", "Times New Roman", serif;
                font-weight: 600; font-size: 28px; line-height: 1.15;
                margin: 0 0 6px; color: var(--ink);
            }
            .nwrn .subtitle { color: var(--ink-soft); font-size: 14.5px; margin: 0 0 24px; max-width: 60ch; }

            .nwrn .current {
                display: flex; align-items: center; gap: 10px;
                background: var(--paper-2); border: 1px solid var(--line); border-radius: 10px;
                padding: 14px 18px; margin-bottom: 28px;
            }
            .nwrn .current .hash {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 13px; font-weight: 700; color: var(--accent);
                background: var(--accent-soft); border-radius: 6px; padding: 2px 8px;
            }
            .nwrn .current .meta { color: var(--ink-soft); font-size: 13px; }

            .nwrn ul { list-style: none; margin: 0; padding: 0; }
            .nwrn .day { margin: 22px 0 8px; font-size: 12px; font-weight: 700; letter-spacing: .04em; color: var(--ink-soft); text-transform: uppercase; }
            .nwrn .day:first-of-type { margin-top: 0; }
            .nwrn li.entry {
                display: flex; gap: 10px; padding: 7px 0; border-top: 1px solid var(--line);
            }
            .nwrn li.entry:first-of-type { border-top: none; }
            .nwrn li.entry .hash {
                font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                font-size: 12px; color: var(--ink-soft); flex-shrink: 0; padding-top: 1px;
            }
            .nwrn li.entry .subject { font-size: 14.5px; }
            .nwrn .empty { color: var(--ink-soft); font-size: 14px; }
        </style>

        <p class="eyebrow">Documentation</p>
        <h1>Release Notes</h1>
        <p class="subtitle">
            Generated automatically on every deploy from the git log — nothing here is hand-maintained,
            so it always matches what's actually running in production.
        </p>

        @if ($release['version'])
            <div class="current">
                <span class="hash">v{{ $release['version'] }}</span>
                <span class="meta">
                    currently live ({{ $release['date'] }} {{ $release['time'] }})
                    @if ($release['hash'])
                        &middot; {{ $release['hash'] }}
                    @endif
                    @if ($release['deployed_at'])
                        &middot; deployed {{ \Illuminate\Support\Carbon::parse($release['deployed_at'])->diffForHumans() }}
                    @endif
                </span>
            </div>
        @endif

        @if (empty($release['commits']))
            <p class="empty">No release snapshot yet — this appears after the next deploy.</p>
        @else
            <ul>
                @php $lastDay = null; @endphp
                @foreach ($release['commits'] as $commit)
                    @if ($commit['date'] !== $lastDay)
                        <li class="day">{{ \Illuminate\Support\Carbon::parse($commit['date'])->translatedFormat('j F Y') }}</li>
                        @php $lastDay = $commit['date']; @endphp
                    @endif
                    <li class="entry">
                        <span class="hash">{{ $commit['hash'] }}</span>
                        <span class="subject">{{ $commit['subject'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
