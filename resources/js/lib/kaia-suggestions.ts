// The answers a traveler can tap instead of typing, for whichever thing Kaia
// just asked for (`InterviewSlot`, declared by the model — see
// App\Enums\InterviewSlot).
//
// Deliberately owned by the frontend, not the model: these are the same few
// closed sets every time, so generating them per turn would pay tokens and
// latency for wording that can drift ("2 weeks" one turn, "fourteen nights"
// the next) and can't be translated. They live in `chat.suggestions.*` of
// each `resources/js/lang/*.json`, so the chip and the message it sends are
// both in the traveler's own language.
//
// A chip's label IS the message it sends. That keeps the transcript readable —
// the conversation reads as if they said it — and keeps Kaia's own inference
// rules ("two weeks" -> 14 nights) doing the work, rather than a second,
// silent encoding the model never sees.
import type { InterviewSlot } from '@/lib/kaia-types';

// vue-i18n's `tm`, narrowed to what this needs. Passed in rather than taken
// from useI18n() here, so this stays a plain function (same reasoning as
// lib/duration.ts).
type MessageArrayResolver = (key: string) => unknown;

/** How many months ahead the travel-period chips offer. */
const MONTH_CHOICES = 6;

/**
 * Travel period is the one slot with no fixed answers — it moves with the
 * calendar — so it is generated rather than translated: the current month and
 * the five after it, named in the traveler's own locale. That is exactly what
 * `ready_for_itinerary` wants ("August 2026"), so tapping one pins a real
 * period rather than a season.
 *
 * `reference` exists for the test; production always passes today.
 */
export function monthSuggestions(locale: string, reference: Date): string[] {
    const formatter = new Intl.DateTimeFormat(locale, {
        month: 'long',
        year: 'numeric',
    });

    return Array.from({ length: MONTH_CHOICES }, (_, offset) =>
        formatter.format(
            // Day 1 on purpose: adding months to e.g. the 31st would skip a
            // 30-day month entirely.
            new Date(reference.getFullYear(), reference.getMonth() + offset, 1),
        ),
    );
}

/**
 * What to offer under Kaia's last message. An empty list is a normal answer —
 * a general question about Namibia has no canned follow-up worth guessing at —
 * and then the traveler simply types, as they always could.
 */
export function suggestionsFor(
    slot: InterviewSlot | null | undefined,
    locale: string,
    tm: MessageArrayResolver,
    reference: Date = new Date(),
): string[] {
    if (!slot) {
        return [];
    }

    if (slot === 'travel_period') {
        return monthSuggestions(locale, reference);
    }

    return asStrings(tm(`chat.suggestions.${slot}`));
}

/** The openers offered under the greeting, before Kaia has said anything. */
export function starterSuggestions(tm: MessageArrayResolver): string[] {
    return asStrings(tm('chat.starters'));
}

// A missing key comes back from `tm` as the key itself (a string) rather than
// as an array, which would otherwise render one chip reading
// "chat.suggestions.nights".
function asStrings(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }

    return value.filter((entry): entry is string => typeof entry === 'string');
}
