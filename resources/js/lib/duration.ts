// Shared formatter for a listing's `duration_minutes` — the trip plan's entry
// rows and the listing preview modal both show it, and they must agree on
// where the minutes/hours cutoff sits.
//
// Takes vue-i18n's `t` rather than calling useI18n() itself, so it stays a
// plain function usable outside a component setup context.
export function formatDuration(
    minutes: number | null | undefined,
    t: (key: string, named: Record<string, unknown>) => string,
): string | null {
    if (!minutes || minutes <= 0) {
        return null;
    }

    if (minutes < 60) {
        return t('itinerary.durationMinutes', { value: minutes });
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    // "1 h 30 min" rather than "1.5 h" — decimal hours read as a calculation,
    // and the decimal separator would need locale handling for nothing.
    return rest === 0
        ? t('itinerary.durationHours', { value: hours })
        : t('itinerary.durationHoursMinutes', { hours, minutes: rest });
}
