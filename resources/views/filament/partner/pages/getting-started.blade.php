@php
    /** @var \App\Models\Listing|null $property */
    /** @var array<int, \App\Filament\Partner\Support\SetupStep> $steps */
    /** @var string|null $state */
    /** @var \App\Services\Booking\BookingMailbox|null $mailbox */
    /** @var string $supportAddress */

    $states = [
        'held' => [
            'name' => 'Not live yet',
            'guest' => 'Held by NamibWay',
            'property' => 'Held by NamibWay',
            'text' => 'Everything works — the calendar, the rates, taking a booking. The only difference '
                . 'is that confirmations go to the NamibWay team rather than out in your name, so nothing '
                . 'reaches a guest while the account is being set up.',
        ],
        'demo' => [
            'name' => 'Test mode',
            'guest' => 'Your test address',
            'property' => 'Your test address',
            'text' => 'You are trying the system out against your own rooms and want to read the mail '
                . 'yourself. Every message goes to one address you choose, and a test booking can never '
                . 'reach a guest.',
        ],
        'live' => [
            'name' => 'Live',
            'guest' => 'The guest',
            'property' => 'Your reservations desk',
            'text' => 'Guests receive their own confirmations and your desk is notified of every booking.',
        ],
    ];
@endphp

<x-filament-panels::page>
    @if ($property === null)
        <x-filament::section>
            <p class="nw-gs__text">
                No property is linked to this account yet. NamibWay sets that up — write to
                <a class="nw-gs__link" href="mailto:{{ $supportAddress }}">{{ $supportAddress }}</a>
                and it is done the same day.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Setting up {{ $property->name }}</x-slot>

            <x-slot name="description">
                Four steps, and the first three are yours. Each one checks itself — nothing here is
                ticked off by hand.
            </x-slot>

            <ol class="nw-gs__steps">
                @foreach ($steps as $index => $step)
                    <li class="nw-gs__step {{ $step->done ? 'nw-gs__step--done' : '' }}">
                        <span class="nw-gs__marker">{{ $step->done ? '✓' : $index + 1 }}</span>

                        <div class="nw-gs__body">
                            <p class="nw-gs__title">{{ $step->title }}</p>
                            <p class="nw-gs__text">{{ $step->body }}</p>
                        </div>

                        @if ($step->hasAction())
                            <a class="nw-gs__action" href="{{ $step->actionUrl }}">
                                {{ $step->actionLabel }} &rarr;
                            </a>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Where booking mail goes</x-slot>

            <x-slot name="description">
                There are three states, and the only thing that differs between them is who receives
                the mail. The screens are identical in all three, on purpose: the system you are
                trying out is the system you get.
            </x-slot>

            <div class="nw-gs__states">
                @foreach ($states as $key => $row)
                    <div class="nw-gs__state {{ $key === $state ? 'nw-gs__state--current' : '' }}">
                        <p class="nw-gs__title">
                            {{ $row['name'] }}
                            @if ($key === $state)
                                <span class="nw-gs__now">This property, now</span>
                            @endif
                        </p>

                        <p class="nw-gs__text">{{ $row['text'] }}</p>

                        <dl class="nw-gs__pairs">
                            <div><dt>Guest confirmation</dt><dd>{{ $row['guest'] }}</dd></div>
                            <div><dt>Property notification</dt><dd>{{ $row['property'] }}</dd></div>
                        </dl>
                    </div>
                @endforeach
            </div>

            @if ($mailbox !== null && $mailbox->holdingNotice() !== null)
                <p class="nw-gs__text nw-gs__note">
                    Right now: {{ $mailbox->holdingNotice() }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Going live, and asking for help</x-slot>

            <p class="nw-gs__text">
                Switching a property on is not a setting in this panel, and that is deliberate: it
                means real guests start receiving real mail in NamibWay's name, so we do it with you
                rather than to you. Write to
                <a class="nw-gs__link" href="mailto:{{ $supportAddress }}?subject={{ rawurlencode('Going live: ' . $property->name) }}">{{ $supportAddress }}</a>
                when your rooms and rates are in, and say which address your reservations desk reads.
                Test mode and that address are ours to set for you too — ask and it takes a minute.
            </p>

            <p class="nw-gs__text">
                The same address is the one for anything else: a rate that will not behave, a booking
                that should not have been possible, a report you need and cannot find.
            </p>
        </x-filament::section>
    @endif

    <style>
        .nw-gs__steps {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .nw-gs__step {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            gap: 0.5rem 0.75rem;
            padding: 0.75rem 0.9rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(228 228 231);
            background-color: rgb(250 250 250);
        }

        .nw-gs__step--done {
            border-color: rgb(var(--success-300, 134 239 172));
            background-color: rgb(var(--success-50, 240 253 244));
        }

        .nw-gs__marker {
            flex: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            font-size: 0.8125rem;
            font-weight: 700;
            border-radius: 9999px;
            color: rgb(255 255 255);
            background-color: rgb(113 113 122);
        }

        .nw-gs__step--done .nw-gs__marker {
            background-color: rgb(21 128 61);
        }

        .nw-gs__body {
            flex: 1 1 20rem;
            min-width: 0;
        }

        .nw-gs__title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: rgb(24 24 27);
        }

        .nw-gs__text {
            margin-top: 0.2rem;
            font-size: 0.8125rem;
            line-height: 1.5;
            color: rgb(82 82 91);
        }

        .nw-gs__note {
            margin-top: 0.9rem;
            font-weight: 600;
        }

        .nw-gs__link {
            font-weight: 600;
            text-decoration: underline;
            color: rgb(146 82 24);
        }

        .nw-gs__action {
            flex: none;
            align-self: center;
            padding: 0.3rem 0.6rem;
            font-size: 0.8125rem;
            font-weight: 600;
            white-space: nowrap;
            border-radius: 0.375rem;
            border: 1px solid rgb(212 212 216);
            color: rgb(39 39 42);
            background-color: rgb(255 255 255);
        }

        .nw-gs__states {
            display: grid;
            gap: 0.6rem;
            grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
        }

        .nw-gs__state {
            padding: 0.75rem 0.9rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(228 228 231);
        }

        .nw-gs__state--current {
            border-color: rgb(146 82 24);
            border-width: 2px;
        }

        .nw-gs__now {
            display: inline-block;
            margin-left: 0.35rem;
            padding: 0.05rem 0.4rem;
            font-size: 0.625rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            vertical-align: 0.08rem;
            border-radius: 0.25rem;
            color: rgb(255 255 255);
            background-color: rgb(146 82 24);
        }

        .nw-gs__pairs {
            margin-top: 0.6rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            font-size: 0.75rem;
        }

        .nw-gs__pairs > div {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            border-top: 1px solid rgb(228 228 231);
            padding-top: 0.25rem;
        }

        .nw-gs__pairs dt {
            color: rgb(113 113 122);
        }

        .nw-gs__pairs dd {
            font-weight: 600;
            text-align: right;
            color: rgb(39 39 42);
        }

        .dark .nw-gs__step,
        .dark .nw-gs__state {
            border-color: rgb(63 63 70);
            background-color: rgb(39 39 42);
        }

        .dark .nw-gs__step--done {
            border-color: rgb(22 101 52);
            background-color: rgb(5 46 22);
        }

        .dark .nw-gs__title,
        .dark .nw-gs__pairs dd {
            color: rgb(244 244 245);
        }

        .dark .nw-gs__text {
            color: rgb(212 212 216);
        }

        .dark .nw-gs__action {
            border-color: rgb(82 82 91);
            color: rgb(244 244 245);
            background-color: rgb(24 24 27);
        }

        .dark .nw-gs__link {
            color: rgb(232 178 116);
        }

        .dark .nw-gs__pairs > div {
            border-color: rgb(63 63 70);
        }
    </style>
</x-filament-panels::page>
