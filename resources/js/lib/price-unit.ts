import { formatPrice } from '@/lib/currency';

// What a listing's `price_from` is quoted per — the frontend half of
// App\Enums\PriceUnit. The two dimensions folded into each value are the ones
// the trip plan has to know before it can add prices up at all: whether the
// rate repeats per night/day, and whether it is charged per traveler.
export type PriceUnit =
    | 'per_night'
    | 'per_person_per_night'
    | 'per_day'
    | 'per_person_per_day'
    | 'per_person'
    | 'per_booking';

const PER_PERSON: readonly PriceUnit[] = [
    'per_person_per_night',
    'per_person_per_day',
    'per_person',
];

const RECURRING: readonly PriceUnit[] = [
    'per_night',
    'per_person_per_night',
    'per_day',
    'per_person_per_day',
];

/** A party of four pays four times this rate. */
export function isPerPerson(unit: PriceUnit | null | undefined): boolean {
    return unit !== null && unit !== undefined && PER_PERSON.includes(unit);
}

/**
 * Whether the rate is charged again for every night/day, as opposed to once for
 * the whole booking. Only matters for the vehicle line, which is the one item
 * the plan multiplies by the trip length itself — a flat package price must not
 * be (a stay is counted per day it appears on, so its own recurrence is already
 * in the day loop).
 */
export function isRecurring(unit: PriceUnit | null | undefined): boolean {
    return unit !== null && unit !== undefined && RECURRING.includes(unit);
}

/**
 * How many times a rate is charged for a party of this size — the multiplier
 * that turns `price_from` into what these travelers actually pay.
 *
 * An unrecorded unit gives 1: before this column existed every price was summed
 * at face value, and silently doubling a stage total the day someone fills in a
 * partner's price unit elsewhere would be worse than staying conservative.
 * "Unknown" means the plan keeps counting exactly as it did.
 */
export function travelerFactor(
    unit: PriceUnit | null | undefined,
    travelers: number,
): number {
    return isPerPerson(unit) ? Math.max(1, travelers) : 1;
}

/**
 * "N$ 1.500/Nacht", "€ 45 p.P." — the price with what it is quoted per, in the
 * traveler's display currency.
 *
 * Returns the bare price when the unit isn't recorded, which is most of the
 * catalog: an unqualified number is honest, and the period this column exists
 * to stop guessing must not come back as a display-time default. `fallbackUnit`
 * is for the one place that has a defensible assumption to fall back on — the
 * stay card, whose price the plan has always counted per night — and is
 * deliberately opt-in per call site rather than baked in here.
 */
export function formatPriceWithUnit(
    price: string | number | null | undefined,
    unit: PriceUnit | null | undefined,
    t: (key: string, named: Record<string, unknown>) => string,
    fallbackUnit?: PriceUnit,
): string | null {
    const formatted = formatPrice(price);

    if (formatted === null) {
        return null;
    }

    const effective = unit ?? fallbackUnit;

    return effective
        ? t(`priceUnit.withPrice.${effective}`, { price: formatted })
        : formatted;
}
