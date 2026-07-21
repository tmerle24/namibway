/**
 * Deterministic stand-in for the live Kaia interview + itinerary generation.
 * The real flow (Claude-driven interview, structured itinerary output) is a
 * separate Core Engine workstream — see CLAUDE.md. This keeps the homepage
 * interaction faithful to the UX prototype without a client-side API key.
 */

export interface ItineraryDay {
    day: number;
    location: string;
    accommodation: string;
    activity: string;
    restaurant: string;
}

export interface ItineraryVariant {
    name: string;
    estimated_total_usd: number;
    days: ItineraryDay[];
}

export interface ItineraryPlan {
    trip_summary: string;
    variants: ItineraryVariant[];
}

export const KAIA_INTERVIEW_STEPS: string[] = [
    "Hi, I'm Kaia. Tell me a bit about the trip you're dreaming of — how many days do you have?",
    'Got it. What kind of experience are you after — wildlife, adventure, relaxation, or a bit of culture?',
    'Nice. And roughly what budget tier should I plan around — budget, mid-range, or premium?',
    "Last thing — who's travelling? Solo, a couple, family, or friends?",
];

export const KAIA_SUMMARY_MESSAGE =
    "Perfect, that's enough to sketch a plan. Here are two ways to do this trip:";

export const DEMO_PLAN: ItineraryPlan = {
    trip_summary:
        "A classic Namibia loop covering Etosha's wildlife, Damaraland's desert scenery, and the dunes of Sossusvlei.",
    variants: [
        {
            name: 'Classic Namibia Loop',
            estimated_total_usd: 2450,
            days: [
                { day: 1, location: 'Windhoek', accommodation: 'Olive Grove Guesthouse', activity: 'City arrival & orientation', restaurant: 'Xwama Cultural Village' },
                { day: 2, location: 'Etosha (Okaukuejo)', accommodation: 'Etosha Safari Lodge', activity: 'Game drive & night hide', restaurant: 'Lodge dinner' },
                { day: 3, location: 'Damaraland', accommodation: 'Doro Nawas Camp', activity: 'Twyfelfontein rock art walk', restaurant: 'Camp dinner' },
                { day: 4, location: 'Swakopmund', accommodation: 'The Delight Hotel', activity: 'Dune quad biking', restaurant: 'The Tug' },
                { day: 5, location: 'Sossusvlei', accommodation: 'Sossus Dune Lodge', activity: 'Sunrise dune climb at Deadvlei', restaurant: 'Lodge dinner' },
            ],
        },
        {
            name: 'Premium Namibia Loop',
            estimated_total_usd: 4200,
            days: [
                { day: 1, location: 'Windhoek', accommodation: 'Olive Grove Guesthouse', activity: 'City arrival & orientation', restaurant: 'Xwama Cultural Village' },
                { day: 2, location: 'Etosha', accommodation: 'Ongava Tented Camp', activity: 'Private game drive & waterhole views', restaurant: 'Camp dinner' },
                { day: 3, location: 'Damaraland', accommodation: 'Doro Nawas Camp', activity: 'Desert-adapted elephant tracking', restaurant: 'Camp dinner' },
                { day: 4, location: 'Sossusvlei', accommodation: 'Little Kulala', activity: 'Sunrise dune climb at Deadvlei', restaurant: 'Lodge dinner' },
                { day: 5, location: 'Fish River Canyon', accommodation: 'Canyon Roadhouse', activity: 'Canyon viewpoint & sundowner drive', restaurant: 'Roadhouse dinner' },
            ],
        },
    ],
};
