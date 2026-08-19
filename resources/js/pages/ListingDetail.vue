<script setup lang="ts">
import '../../css/kaia-home.css';
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import { Globe, Pencil, UserPlus } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminBar from '@/components/AdminBar.vue';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import ExploreMap from '@/components/home/ExploreMap.vue';
import type { ExploreMapMarker } from '@/components/home/ExploreMap.vue';
import MobileFooterNav from '@/components/home/MobileFooterNav.vue';
import SaveLoginModal from '@/components/home/SaveLoginModal.vue';
import ImageLightbox from '@/components/ImageLightbox.vue';
import InputError from '@/components/InputError.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import NavMoreMenu from '@/components/NavMoreMenu.vue';
import PublishConsentModal from '@/components/PublishConsentModal.vue';
import SiteFooter from '@/components/SiteFooter.vue';
import SiteHeader from '@/components/SiteHeader.vue';
import { formatPrice } from '@/lib/currency';
import { onImageError, thumb, thumbAttrs } from '@/lib/media';
import type { PriceUnit } from '@/lib/price-unit';
import { formatPriceWithUnit } from '@/lib/price-unit';
import { home } from '@/routes';
import inquiries from '@/routes/listings/inquiries';
import reviewRoutes from '@/routes/listings/reviews';
import logoDark from '../../images/logo-dark.png';

const FALLBACK_HERO_IMAGES: Record<Listing['type'], string[]> = {
    accommodation: [
        '/images/explore/accommodation-1.jpg',
        '/images/explore/accommodation-2.jpg',
        '/images/explore/accommodation-3.jpg',
        '/images/explore/accommodation-4.jpg',
    ],
    activity: [
        '/images/explore/activity-1.jpg',
        '/images/explore/activity-2.jpg',
        '/images/explore/activity-3.jpg',
        '/images/explore/activity-4.jpg',
    ],
    restaurant: [
        '/images/explore/restaurant-1.jpg',
        '/images/explore/restaurant-2.jpg',
        '/images/explore/restaurant-3.jpg',
        '/images/explore/restaurant-4.jpg',
    ],
    vehicle: [
        '/images/explore/vehicle-1.jpg',
        '/images/explore/vehicle-2.jpg',
        '/images/explore/vehicle-3.jpg',
        '/images/explore/vehicle-4.jpg',
    ],
};

type SocialPlatform =
    | 'facebook'
    | 'instagram'
    | 'youtube'
    | 'twitter'
    | 'linkedin'
    | 'tiktok'
    | 'pinterest'
    | 'tripadvisor'
    | 'vimeo';

interface Partner {
    name: string;
    logo: string | null;
    website: string | null;
    instagram: string | null;
    facebook: string | null;
}

interface Listing {
    id: number;
    type: 'accommodation' | 'activity' | 'restaurant' | 'vehicle';
    name: string;
    slug: string;
    description: string | null;
    highlights: string[];
    image: string | null;
    gallery: string[];
    photos_source:
        | 'partner'
        | 'manual'
        | 'website_scrape'
        | 'ai_generated'
        | 'google_places'
        | 'directory'
        | null;
    photos_attribution: string | null;
    pending_image: string | null;
    pending_gallery: string[];
    pending_photos_source: string | null;
    region: string | null;
    area: string | null;
    city: string | null;
    address: string | null;
    phone: string | null;
    phone_href: string | null;
    website: string | null;
    social_links: Partial<Record<SocialPlatform, string>>;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
    price_unit: PriceUnit | null;
    rating: number | null;
    rating_count: number | null;
    accepts_inquiries: boolean;
    contact_person: string | null;
    partner: Partner | null;
}

interface Review {
    id: number;
    name: string;
    rating: number;
    comment: string;
    created_at: string;
}

/** One line of a restaurant's menu — see App\Models\MenuItem. */
interface MenuItem {
    id: number;
    name: string;
    description: string | null;
    price: number;
    currency: string;
    image: string | null;
}

interface MenuSection {
    category: string;
    items: MenuItem[];
}

const props = defineProps<{
    listing: Listing;
    /**
     * Empty for everything that is not a restaurant with a menu entered. Sent
     * whether or not the restaurant takes orders — a menu that is only being
     * shown is exactly what the ordering switch is for.
     */
    menu: MenuSection[];
    /** What the property is willing to be asked for — Listing::requestKinds(). */
    can_reserve_table: boolean;
    can_order: boolean;
    reviews: Review[];
    is_preview?: boolean;
    can_publish?: boolean;
    can_approve_photos?: boolean;
    preview_token?: string | null;
    claim_url?: string | null;
}>();

// The logged-in account, if any — an inquiry is a booking request and needs
// one. Reactive, because logging in through the modal below resolves it
// without leaving the page.
const account = computed(
    () =>
        (
            usePage().props.auth as
                { user: { name?: string; email?: string } | null } | undefined
        )?.user ?? null,
);

// A deliberate one-shot snapshot for prefilling: a reactive :value would
// overwrite whatever the traveler typed the moment they log in, and send the
// account's name instead of the one they entered for this trip.
const initialAccount = account.value;

const { t } = useI18n();

const inquiryForm = ref<{ submit: () => void } | null>(null);
const showInquiryLogin = ref(false);

// ---------------------------------------------------------------------------
// Restaurants: a table, or an order
// ---------------------------------------------------------------------------
// A restaurant is asked for two different things, and asking for both on one
// form would mean a guest ordering two burgers first telling us how many
// children are coming. So the panel has two tabs and each renders only its own
// fields — the shared contact fields stay put underneath, and the whole thing
// is still one <Form>, so the logged-out login modal keeps working unchanged.

const isRestaurant = computed(() => props.listing.type === 'restaurant');

// Both come from the server, which resolves them once from the property's own
// switches and enforces the same list on the POST — see Listing::requestKinds().
// The page never decides this for itself; a tab the server would refuse is a
// tab that should not be drawn.
const canReserveTable = computed(() => props.can_reserve_table);
const canOrder = computed(() => props.can_order);

// Only worth a tab strip when there really are two things to choose between.
const hasBothChannels = computed(() => canReserveTable.value && canOrder.value);

// The menu as something to read rather than order from — the state a
// restaurant is in when it has published its card but does not take orders
// online. With ordering on, the same items are in the panel with steppers
// beside them, and printing them twice would just be twice.
const showsMenuAsCard = computed(
    () => isRestaurant.value && !canOrder.value && props.menu.length > 0,
);

// Starts on whichever channel is open; with both open, a table is the more
// common ask and goes first.
const inquiryMode = ref<'table' | 'order'>(
    props.can_reserve_table ? 'table' : 'order',
);

/** menu item id → how many. Absent means none; a stepper never goes below 0. */
const orderQuantities = ref<Record<number, number>>({});

const menuItemsById = computed(() => {
    const items = new Map<number, MenuItem>();

    for (const section of props.menu) {
        for (const item of section.items) {
            items.set(item.id, item);
        }
    }

    return items;
});

const orderLines = computed(() =>
    Object.entries(orderQuantities.value)
        .map(([id, quantity]) => ({
            item: menuItemsById.value.get(Number(id)),
            quantity,
        }))
        .filter(
            (line): line is { item: MenuItem; quantity: number } =>
                line.item !== undefined && line.quantity > 0,
        ),
);

const orderItemCount = computed(() =>
    orderLines.value.reduce((total, line) => total + line.quantity, 0),
);

// A running total in the traveler's own display currency, exactly like every
// other price on the page. It is a preview and nothing more: the total that is
// stored and sent to the kitchen is added up server-side from the menu in the
// database (App\Services\Booking\MenuOrder), never from this number.
const orderTotal = computed(() =>
    orderLines.value.reduce(
        (total, line) => total + line.item.price * line.quantity,
        0,
    ),
);

function setQuantity(item: MenuItem, quantity: number) {
    const next = Math.min(99, Math.max(0, quantity));

    if (next === 0) {
        delete orderQuantities.value[item.id];

        return;
    }

    orderQuantities.value[item.id] = next;
}

function quantityOf(item: MenuItem): number {
    return orderQuantities.value[item.id] ?? 0;
}

/**
 * What actually goes on the wire beside the typed contact fields.
 *
 * Ids and counts only — no names and no prices. A form that posts a price is a
 * form somebody can edit to post a different one.
 */
function withRequestKind(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    if (!isRestaurant.value) {
        return data;
    }

    if (inquiryMode.value === 'order') {
        return {
            ...data,
            kind: 'order',
            items: orderLines.value.map((line) => ({
                menu_item_id: line.item.id,
                quantity: line.quantity,
            })),
        };
    }

    return { ...data, kind: 'table_reservation' };
}

// Nothing picked yet is not an error to show after the fact — the button says
// so instead.
const inquiryBlocked = computed(
    () =>
        isRestaurant.value &&
        inquiryMode.value === 'order' &&
        orderLines.value.length === 0,
);

function onOrderSent() {
    orderQuantities.value = {};
}

/** Today in the browser's own timezone — a `min` of "yesterday" in Windhoek is a bug. */
const today = computed(() => {
    const now = new Date();

    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
});

// "Request availability" is what a lodge is asked; a restaurant is asked for a
// table or for dinner, and the panel should say which.
const inquiryTitle = computed(() => {
    if (!isRestaurant.value) {
        return t('listing.inquiry.title');
    }

    return inquiryMode.value === 'order'
        ? t('listing.inquiry.orderTitle')
        : t('listing.inquiry.tableTitle');
});

const inquirySendLabel = computed(() =>
    isRestaurant.value && inquiryMode.value === 'order'
        ? t('listing.inquiry.sendOrder')
        : isRestaurant.value
          ? t('listing.inquiry.sendTable')
          : t('listing.inquiry.send'),
);

const inquirySuccessLabel = computed(() =>
    isRestaurant.value && inquiryMode.value === 'order'
        ? t('listing.inquiry.orderSuccess')
        : t('listing.inquiry.success'),
);

function onInquirySend(event: MouseEvent) {
    // Logged in: the button is a real submit and this does nothing.
    if (account.value) {
        return;
    }

    // type="button" skips the browser's own required-field check, so run it
    // by hand — otherwise an empty form sends the traveler through a login
    // only to come back with validation errors.
    const form = (event.currentTarget as HTMLElement).closest('form');

    if (form && !form.reportValidity()) {
        return;
    }

    showInquiryLogin.value = true;
}

async function onInquiryAuthenticated() {
    showInquiryLogin.value = false;

    // Let the refreshed auth prop land before submitting, so the button is a
    // submit again and the request carries the new session.
    await nextTick();
    inquiryForm.value?.submit();
}

const showPublishModal = ref(false);
const lightboxIndex = ref<number | null>(null);
const pendingPhotoCount = computed(
    () =>
        props.listing.pending_gallery.length +
        (props.listing.pending_image ? 1 : 0),
);

// Staged photos that no approval can publish (see App\Enums\ContentSource).
// The server only sends pending photos to someone allowed to see them, so their
// presence is the visibility check.
const referenceOnlyPhotoCount = computed(() =>
    props.listing.pending_photos_source === 'directory'
        ? pendingPhotoCount.value
        : 0,
);

function publishListing() {
    showPublishModal.value = true;
}

function confirmPublish() {
    showPublishModal.value = false;

    router.post(
        `/listings/${props.listing.slug}/publish`,
        { preview: props.preview_token ?? undefined, terms_accepted: true },
        { preserveScroll: true },
    );
}

function approvePendingPhotos() {
    if (
        !window.confirm(
            'Publish these photos on the listing? Only do this once you have the right to use them.',
        )
    ) {
        return;
    }

    router.post(
        `/listings/${props.listing.slug}/approve-photos`,
        { preview: props.preview_token ?? undefined },
        { preserveScroll: true },
    );
}

// Stated with its unit where the listing records one — this page is where a
// traveler decides, so "from €45" and "from €45 per person" must not look the
// same. See @/lib/price-unit.
const priceLabel = computed(() =>
    formatPriceWithUnit(props.listing.price_from, props.listing.price_unit, t),
);

const reviewRatingInput = ref(0);
const reviewRatingHover = ref(0);

const heroImage = computed(() => {
    if (props.listing.image) {
        return props.listing.image;
    }

    const fallbacks = FALLBACK_HERO_IMAGES[props.listing.type];

    return fallbacks[props.listing.id % fallbacks.length];
});

// What goes in brackets after the town: the tourism area a traveler thinks in
// ("Onguma Nature Reserve (Etosha)"), falling back to the political region for
// a place that belongs to no area yet — coarse, but better than nothing, and
// it disappears on its own as areas get filled in. Suppressed when it would
// only repeat the town, which is the case for Windhoek and Swakopmund, where
// the town IS the area.
const locationArea = computed(() => {
    const area = props.listing.area ?? props.listing.region;

    if (!area) {
        return null;
    }

    const city = props.listing.city;

    return city && city.toLowerCase().trim() === area.toLowerCase().trim()
        ? null
        : area;
});

// The hero is a CSS background, so it can't carry a srcset — ask for the top
// of the width ladder and let format=auto do the rest. `heroImage` itself stays
// unresized, because exploreMarkers reuses it at 48px.
const heroImageCss = computed(() => thumb(heroImage.value, 1600));

// Carries forward whatever explore filters were active when this listing
// was opened (see ExploreSection.vue's listingUrl()), so "back" restores
// the previous search instead of dropping the user on a blank homepage.
const FORWARDED_FILTER_KEYS = [
    'type',
    'region',
    'city',
    'budget',
    'keyword',
    'min_rating',
    'sort',
];

const backHref = computed(() => {
    const forwarded = new URLSearchParams(window.location.search);
    const query: Record<string, string> = {};

    for (const key of FORWARDED_FILTER_KEYS) {
        const value = forwarded.get(key);

        if (value) {
            query[key] = value;
        }
    }

    return Object.keys(query).length > 0 ? home({ query }) : home();
});

const hasLocation = computed(
    () => props.listing.latitude !== null && props.listing.longitude !== null,
);

const locationMarkers = computed<ExploreMapMarker[]>(() => {
    if (props.listing.latitude === null || props.listing.longitude === null) {
        return [];
    }

    return [
        {
            title: props.listing.name,
            typeLabel: t(`listing.types.${props.listing.type}`),
            image: heroImage.value,
            slug: null,
            address: props.listing.address,
            latitude: props.listing.latitude,
            longitude: props.listing.longitude,
        },
    ];
});

const directionsUrl = computed(() => {
    if (props.listing.latitude === null || props.listing.longitude === null) {
        return null;
    }

    return `https://www.google.com/maps/dir/?api=1&destination=${props.listing.latitude},${props.listing.longitude}`;
});

const websiteUrl = computed(
    () => props.listing.partner?.website || props.listing.website,
);

// Fixed order so the sidebar reads the same on every listing, regardless of
// which platforms a given source happened to publish.
const SOCIAL_ORDER: SocialPlatform[] = [
    'facebook',
    'instagram',
    'youtube',
    'tiktok',
    'twitter',
    'linkedin',
    'pinterest',
    'vimeo',
    'tripadvisor',
];

const socialLinks = computed(() =>
    SOCIAL_ORDER.filter(
        (platform) => !!props.listing.social_links?.[platform],
    ).map((platform) => ({
        platform,
        url: props.listing.social_links[platform] as string,
        label: t(`listing.links.${platform}`),
    })),
);
</script>

<template>
    <Head :title="props.listing.name" />

    <div class="kaia-page">
        <PublishConsentModal
            :show="showPublishModal"
            :listing-name="props.listing.name"
            :pending-photo-count="pendingPhotoCount"
            @confirm="confirmPublish"
            @cancel="showPublishModal = false"
        />

        <AdminBar
            :edit-url="`/admin/listings/${props.listing.id}/edit`"
            :listing-slug="props.listing.slug"
        />

        <!-- Touch devices only — the desktop topbar below (.detail-topbar)
             stays exactly as-is; see kaia-home.css. -->
        <div class="detail-mobile-header">
            <SiteHeader />
        </div>

        <!-- Same owner actions as .detail-topbar-actions below, just in their
             own bar under the header — owners very often open the claim
             email straight from their phone, so these can't be mobile-only
             hidden the way the rest of .detail-topbar is. -->
        <div
            v-if="props.can_publish || props.claim_url"
            class="detail-mobile-owner-bar"
        >
            <template v-if="props.can_publish">
                <Link
                    :href="`/listings/${props.listing.slug}/edit${props.preview_token ? `?preview=${props.preview_token}` : ''}`"
                    class="owner-header-link owner-header-link--edit"
                >
                    <Pencil :size="14" />
                    Edit
                </Link>
                <button
                    v-if="props.is_preview"
                    type="button"
                    class="owner-header-link owner-header-link--publish"
                    @click="publishListing"
                >
                    <Globe :size="14" />
                    Publish
                </button>
            </template>
            <a
                v-if="props.claim_url"
                :href="props.claim_url"
                class="owner-header-link owner-header-link--claim"
            >
                <UserPlus :size="14" />
                Claim account
            </a>
        </div>

        <div v-if="props.is_preview" class="draft-banner">
            <span>Draft preview</span>
            <span class="draft-banner-badge">unpublished</span>
            <button
                v-if="props.can_publish"
                type="button"
                class="draft-banner-publish"
                @click="publishListing"
            >
                <Globe :size="14" />
                Publish
            </button>
        </div>

        <!-- Preview only — publishing (above) approves these in the same click, so
             there's no separate action here. Once the listing is live, any *later*
             pending photos (e.g. a re-scrape) get their own approve panel below,
             since there's no "publish the whole listing" step to piggyback on then. -->
        <div
            v-if="
                props.is_preview &&
                props.can_approve_photos &&
                (props.listing.pending_image ||
                    props.listing.pending_gallery.length)
            "
            class="pending-photos-preview"
        >
            <p>
                Publishing will also include
                {{
                    props.listing.pending_gallery.length +
                    (props.listing.pending_image ? 1 : 0)
                }}
                photo(s) found on your website:
            </p>
            <div class="pending-photos-thumbs">
                <img
                    v-if="props.listing.pending_image"
                    v-bind="thumbAttrs(props.listing.pending_image, 56)"
                    alt="Pending hero image"
                    loading="lazy"
                    decoding="async"
                />
                <img
                    v-for="(src, i) in props.listing.pending_gallery"
                    :key="i"
                    v-bind="thumbAttrs(src, 56)"
                    :alt="`Pending gallery image ${i + 1}`"
                    loading="lazy"
                    decoding="async"
                />
            </div>
        </div>

        <!-- Staged photos from a third-party directory. Visible to an admin or the
             owner as reference while a listing is being matched up, but there is no
             approve action: nobody here can license someone else's photography. -->
        <div v-if="referenceOnlyPhotoCount" class="pending-photos-preview">
            <p>
                {{ referenceOnlyPhotoCount }} photo(s) from
                {{ props.listing.pending_photos_source }} — reference only, not
                publishable. They disappear once the listing's own website is
                crawled or the owner uploads their own.
            </p>
            <div class="pending-photos-thumbs">
                <img
                    v-if="props.listing.pending_image"
                    v-bind="thumbAttrs(props.listing.pending_image, 56)"
                    alt="Reference image"
                    loading="lazy"
                    decoding="async"
                />
                <img
                    v-for="(src, i) in props.listing.pending_gallery"
                    :key="i"
                    v-bind="thumbAttrs(src, 56)"
                    :alt="`Reference image ${i + 1}`"
                    loading="lazy"
                    decoding="async"
                />
            </div>
        </div>

        <div
            v-if="
                !props.is_preview &&
                props.can_approve_photos &&
                (props.listing.pending_image ||
                    props.listing.pending_gallery.length)
            "
            :style="{
                background: '#1e3a5f',
                color: '#dbeafe',
                padding: '10px 12px',
                fontSize: '14px',
            }"
        >
            <p style="margin: 0 0 8px">
                We found
                {{
                    props.listing.pending_gallery.length +
                    (props.listing.pending_image ? 1 : 0)
                }}
                photo(s) on the property's own website. We only publish them
                once you confirm we have the right to use them.
            </p>
            <div
                style="
                    display: flex;
                    gap: 8px;
                    overflow-x: auto;
                    margin-bottom: 8px;
                "
            >
                <img
                    v-if="props.listing.pending_image"
                    v-bind="thumbAttrs(props.listing.pending_image, 64)"
                    alt="Pending hero image"
                    loading="lazy"
                    decoding="async"
                    style="
                        height: 64px;
                        width: 64px;
                        object-fit: cover;
                        border-radius: 6px;
                        flex-shrink: 0;
                    "
                />
                <img
                    v-for="(src, i) in props.listing.pending_gallery"
                    :key="i"
                    v-bind="thumbAttrs(src, 64)"
                    :alt="`Pending gallery image ${i + 1}`"
                    loading="lazy"
                    decoding="async"
                    style="
                        height: 64px;
                        width: 64px;
                        object-fit: cover;
                        border-radius: 6px;
                        flex-shrink: 0;
                    "
                />
            </div>
            <button
                type="button"
                :style="{
                    background: '#2563eb',
                    color: '#fff',
                    border: 'none',
                    borderRadius: '6px',
                    padding: '6px 14px',
                    fontSize: '13px',
                    fontWeight: 600,
                    cursor: 'pointer',
                }"
                @click="approvePendingPhotos"
            >
                Approve and publish these photos
            </button>
        </div>

        <div class="detail-topbar">
            <Link :href="home()" class="brand"
                ><img :src="logoDark" alt="NamibWay" class="brand-logo"
            /></Link>
            <div class="detail-topbar-actions">
                <template v-if="props.can_publish">
                    <Link
                        :href="`/listings/${props.listing.slug}/edit${props.preview_token ? `?preview=${props.preview_token}` : ''}`"
                        class="owner-header-link owner-header-link--edit"
                    >
                        <Pencil :size="14" />
                        Edit
                    </Link>
                    <button
                        v-if="props.is_preview"
                        type="button"
                        class="owner-header-link owner-header-link--publish"
                        @click="publishListing"
                    >
                        <Globe :size="14" />
                        Publish
                    </button>
                </template>
                <a
                    v-if="props.claim_url"
                    :href="props.claim_url"
                    class="owner-header-link owner-header-link--claim"
                >
                    <UserPlus :size="14" />
                    Claim account
                </a>
                <Link
                    v-if="!props.claim_url"
                    :href="backHref"
                    class="detail-back"
                    >{{ t('listing.backToHome') }}</Link
                >
                <LocaleSwitcher />
                <NavMoreMenu>
                    <CurrencySwitcher variant="full" />
                </NavMoreMenu>
            </div>
        </div>

        <div
            class="detail-hero"
            :style="{ backgroundImage: `url(${heroImageCss})` }"
        >
            <div class="detail-hero-overlay">
                <Link
                    :href="home({ query: { type: props.listing.type } })"
                    class="idea-tag"
                    >{{ t(`listing.types.${props.listing.type}`) }}</Link
                >
                <div class="detail-title-row">
                    <h1>{{ props.listing.name }}</h1>
                    <Link
                        v-if="!props.claim_url"
                        :href="backHref"
                        class="detail-back detail-title-back"
                        >{{ t('listing.backToHome') }}</Link
                    >
                </div>
                <p v-if="props.listing.city || locationArea">
                    <Link
                        v-if="props.listing.city"
                        :href="home({ query: { city: props.listing.city } })"
                        class="detail-region-link"
                        >{{ props.listing.city }}</Link
                    >
                    <Link
                        v-if="locationArea"
                        :href="home({ query: { region: locationArea } })"
                        class="detail-region-link"
                        :class="{ 'detail-region-sub': props.listing.city }"
                        >{{
                            props.listing.city
                                ? `(${locationArea})`
                                : locationArea
                        }}</Link
                    >
                </p>
                <p v-if="props.listing.rating !== null" class="detail-rating">
                    ★ {{ props.listing.rating.toFixed(1) }}
                    <span v-if="props.listing.rating_count">
                        ({{
                            t('listing.reviews.count', {
                                count: props.listing.rating_count,
                            })
                        }})
                    </span>
                </p>
                <p v-if="priceLabel" class="detail-price">
                    {{ t('listing.from') }}
                    {{ priceLabel }}
                </p>
            </div>
        </div>

        <section v-if="props.listing.gallery.length">
            <div class="section-head">
                <h2>{{ t('listing.gallery') }}</h2>
            </div>
            <div class="detail-gallery">
                <button
                    v-for="(src, i) in props.listing.gallery"
                    :key="i"
                    type="button"
                    class="gallery-thumb-btn"
                    @click="lightboxIndex = i"
                >
                    <img
                        v-bind="thumbAttrs(src, 280)"
                        :alt="`${props.listing.name} ${i + 1}`"
                        width="280"
                        height="160"
                        loading="lazy"
                        decoding="async"
                        @error="onImageError($event, props.listing.type)"
                    />
                </button>
            </div>
        </section>

        <ImageLightbox
            v-if="lightboxIndex !== null"
            :images="props.listing.gallery"
            :index="lightboxIndex"
            :alt="props.listing.name"
            :attribution="
                props.listing.photos_source === 'google_places'
                    ? props.listing.photos_attribution
                    : null
            "
            @update:index="lightboxIndex = $event"
            @close="lightboxIndex = null"
        />

        <!-- Google's Places API terms require crediting photo contributors — this HTML
             comes straight from Google's own API response (html_attributions), not user
             input, so v-html is safe here. -->
        <p
            v-if="
                props.listing.photos_source === 'google_places' &&
                props.listing.photos_attribution
            "
            class="photo-attribution"
            v-html="'Photos: ' + props.listing.photos_attribution"
        ></p>

        <section>
            <div class="detail-grid">
                <div>
                    <div v-if="props.listing.description" class="section-head">
                        <h2>{{ t('listing.about') }}</h2>
                        <!-- description is sanitized server-side on every write path — see
                             Listing::setDescriptionAttribute() — before it ever reaches here. -->
                        <div
                            class="listing-description"
                            v-html="props.listing.description"
                        ></div>
                    </div>

                    <div
                        v-if="props.listing.highlights.length"
                        class="steckbrief"
                    >
                        <h3>{{ t('listing.steckbrief') }}</h3>
                        <ul>
                            <li
                                v-for="highlight in props.listing.highlights"
                                :key="highlight"
                            >
                                {{ highlight }}
                            </li>
                        </ul>
                    </div>

                    <!--
                        The card, to read. Only when the restaurant does not
                        take orders online — with ordering on, these same items
                        are in the panel with a stepper beside each, and
                        printing them twice would just be twice.
                    -->
                    <div v-if="showsMenuAsCard" class="menu-card">
                        <h3>{{ t('listing.menu.title') }}</h3>
                        <p class="menu-card-note">
                            {{ t('listing.menu.readOnly') }}
                        </p>
                        <div
                            v-for="section in props.menu"
                            :key="section.category"
                            class="menu-card-section"
                        >
                            <h4>{{ section.category }}</h4>
                            <div
                                v-for="item in section.items"
                                :key="item.id"
                                class="menu-card-item"
                            >
                                <div class="menu-card-item-text">
                                    <strong>{{ item.name }}</strong>
                                    <span
                                        v-if="item.description"
                                        class="menu-item-description"
                                        >{{ item.description }}</span
                                    >
                                </div>
                                <span class="menu-card-item-price">{{
                                    formatPrice(item.price)
                                }}</span>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="
                            props.listing.partner ||
                            props.listing.address ||
                            props.listing.phone ||
                            websiteUrl
                        "
                        class="contact-card"
                    >
                        <h3>{{ t('listing.contact.title') }}</h3>
                        <p
                            v-if="props.listing.partner"
                            class="contact-partner-name"
                        >
                            {{ props.listing.partner.name }}
                        </p>
                        <p v-if="props.listing.address" class="contact-address">
                            {{ props.listing.address }}
                        </p>
                        <div class="contact-links">
                            <a
                                v-if="props.listing.phone"
                                :href="
                                    props.listing.phone_href ??
                                    `tel:${props.listing.phone}`
                                "
                                >{{ props.listing.phone }}</a
                            >
                            <a
                                v-if="websiteUrl"
                                :href="websiteUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.website') }}</a
                            >
                        </div>
                    </div>

                    <div v-if="socialLinks.length" class="links-card">
                        <h3>{{ t('listing.links.title') }}</h3>
                        <div class="further-links">
                            <a
                                v-for="link in socialLinks"
                                :key="link.platform"
                                :href="link.url"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                >{{ link.label }}</a
                            >
                        </div>
                    </div>

                    <div v-if="hasLocation" class="location-card">
                        <h3>{{ t('listing.location.title') }}</h3>
                        <ExploreMap
                            :markers="locationMarkers"
                            :map-id="`listing-map-${props.listing.id}`"
                        />
                        <a
                            v-if="directionsUrl"
                            :href="directionsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="detail-directions-link"
                            >{{ t('listing.location.directions') }}</a
                        >
                    </div>
                </div>

                <div class="inquiry-panel">
                    <template v-if="props.listing.accepts_inquiries">
                        <h3>{{ inquiryTitle }}</h3>
                        <!--
                            Two tabs only where there really are two things to
                            choose between. A restaurant that takes only one of
                            them — or has no menu entered — shows that one form
                            on its own, with no hint that something is missing.
                        -->
                        <div v-if="hasBothChannels" class="inquiry-mode-tabs">
                            <button
                                type="button"
                                :class="{ active: inquiryMode === 'table' }"
                                @click="inquiryMode = 'table'"
                            >
                                {{ t('listing.inquiry.modes.table') }}
                            </button>
                            <button
                                type="button"
                                :class="{ active: inquiryMode === 'order' }"
                                @click="inquiryMode = 'order'"
                            >
                                {{ t('listing.inquiry.modes.order') }}
                            </button>
                        </div>
                        <p
                            v-if="props.listing.contact_person"
                            class="inquiry-contact-person"
                        >
                            {{
                                t('listing.inquiry.contactPerson', {
                                    name: props.listing.contact_person,
                                })
                            }}
                        </p>
                        <p class="inquiry-subtitle">
                            {{
                                props.listing.partner
                                    ? t('listing.inquiry.subtitle', {
                                          partner: props.listing.partner.name,
                                      })
                                    : t('listing.inquiry.subtitleGeneric')
                            }}
                        </p>
                        <!--
                            Sending an inquiry is a booking request, so it needs
                            an account (listings.inquiries.store is behind
                            `auth`). The form is shown either way; the login is
                            asked for in a modal at the moment of sending, so
                            nothing typed in here is lost to a page change.
                        -->
                        <Form
                            ref="inquiryForm"
                            v-bind="
                                inquiries.store.form({
                                    listing: props.listing.slug,
                                })
                            "
                            reset-on-success
                            :transform="withRequestKind"
                            v-slot="{ errors, processing, recentlySuccessful }"
                            class="inquiry-form"
                            @success="onOrderSent"
                        >
                            <label>
                                {{ t('listing.inquiry.name') }}
                                <input
                                    name="name"
                                    type="text"
                                    required
                                    :value="initialAccount?.name"
                                />
                                <InputError :message="errors.name" />
                            </label>
                            <label>
                                {{ t('listing.inquiry.email') }}
                                <input
                                    name="email"
                                    type="email"
                                    required
                                    :value="initialAccount?.email"
                                />
                                <InputError :message="errors.email" />
                            </label>
                            <label>
                                {{ t('listing.inquiry.phone') }}
                                <input name="phone" type="text" />
                                <InputError :message="errors.phone" />
                            </label>
                            <!--
                                A stay: arrival and departure, both optional —
                                "sometime in June" is still a request worth
                                sending. Unchanged from before restaurants had
                                a form of their own.
                            -->
                            <template v-if="!isRestaurant">
                                <div class="inquiry-dates-row">
                                    <label class="inquiry-date-field">
                                        {{ t('listing.inquiry.checkIn') }}
                                        <input
                                            name="check_in"
                                            type="date"
                                            :min="today"
                                        />
                                        <InputError
                                            :message="errors.check_in"
                                        />
                                    </label>
                                    <label class="inquiry-date-field">
                                        {{ t('listing.inquiry.checkOut') }}
                                        <input
                                            name="check_out"
                                            type="date"
                                            :min="today"
                                        />
                                        <InputError
                                            :message="errors.check_out"
                                        />
                                    </label>
                                </div>
                                <div class="inquiry-guests-row">
                                    <label class="inquiry-guest-field">
                                        {{ t('listing.inquiry.adults') }}
                                        <input
                                            name="adults"
                                            type="number"
                                            min="1"
                                            max="20"
                                            value="2"
                                        />
                                        <InputError :message="errors.adults" />
                                    </label>
                                    <label class="inquiry-guest-field">
                                        {{ t('listing.inquiry.children') }}
                                        <input
                                            name="children"
                                            type="number"
                                            min="0"
                                            max="20"
                                            value="0"
                                        />
                                        <InputError
                                            :message="errors.children"
                                        />
                                    </label>
                                </div>
                            </template>

                            <!--
                                A table: a date and a time, both required, and
                                no departure. A restaurant cannot hold "dinner
                                sometime", and asking afterwards costs the
                                partner an email this form can save them.
                            -->
                            <template v-else-if="inquiryMode === 'table'">
                                <div class="inquiry-dates-row">
                                    <label class="inquiry-date-field">
                                        {{ t('listing.inquiry.date') }}
                                        <input
                                            name="check_in"
                                            type="date"
                                            required
                                            :min="today"
                                        />
                                        <InputError
                                            :message="errors.check_in"
                                        />
                                    </label>
                                    <label class="inquiry-date-field">
                                        {{ t('listing.inquiry.time') }}
                                        <input
                                            name="arrival_time"
                                            type="time"
                                            required
                                            value="19:00"
                                        />
                                        <InputError
                                            :message="errors.arrival_time"
                                        />
                                    </label>
                                </div>
                                <div class="inquiry-guests-row">
                                    <label class="inquiry-guest-field">
                                        {{ t('listing.inquiry.adults') }}
                                        <input
                                            name="adults"
                                            type="number"
                                            min="1"
                                            max="20"
                                            value="2"
                                            required
                                        />
                                        <InputError :message="errors.adults" />
                                    </label>
                                    <label class="inquiry-guest-field">
                                        {{ t('listing.inquiry.children') }}
                                        <input
                                            name="children"
                                            type="number"
                                            min="0"
                                            max="20"
                                            value="0"
                                        />
                                        <InputError
                                            :message="errors.children"
                                        />
                                    </label>
                                </div>
                            </template>

                            <!--
                                An order: the menu, with a stepper per line. No
                                date, no time, no party size — the ask is the
                                list, and every field that is not part of it is
                                a question nobody has an answer to.
                            -->
                            <div v-else class="menu-picker">
                                <div
                                    v-for="section in props.menu"
                                    :key="section.category"
                                    class="menu-section"
                                >
                                    <h4>{{ section.category }}</h4>
                                    <div
                                        v-for="item in section.items"
                                        :key="item.id"
                                        class="menu-item"
                                        :class="{
                                            picked: quantityOf(item) > 0,
                                        }"
                                    >
                                        <img
                                            v-if="item.image"
                                            v-bind="thumbAttrs(item.image, 96)"
                                            :alt="item.name"
                                            class="menu-item-photo"
                                            loading="lazy"
                                            @error="onImageError"
                                        />
                                        <div class="menu-item-text">
                                            <strong>{{ item.name }}</strong>
                                            <span
                                                v-if="item.description"
                                                class="menu-item-description"
                                                >{{ item.description }}</span
                                            >
                                            <span class="menu-item-price">{{
                                                formatPrice(item.price)
                                            }}</span>
                                        </div>
                                        <div class="menu-item-stepper">
                                            <button
                                                type="button"
                                                :aria-label="
                                                    t(
                                                        'listing.inquiry.remove',
                                                        {
                                                            item: item.name,
                                                        },
                                                    )
                                                "
                                                :disabled="
                                                    quantityOf(item) === 0
                                                "
                                                @click="
                                                    setQuantity(
                                                        item,
                                                        quantityOf(item) - 1,
                                                    )
                                                "
                                            >
                                                −
                                            </button>
                                            <span
                                                class="menu-item-quantity"
                                                aria-live="polite"
                                                >{{ quantityOf(item) }}</span
                                            >
                                            <button
                                                type="button"
                                                :aria-label="
                                                    t('listing.inquiry.add', {
                                                        item: item.name,
                                                    })
                                                "
                                                @click="
                                                    setQuantity(
                                                        item,
                                                        quantityOf(item) + 1,
                                                    )
                                                "
                                            >
                                                +
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p class="menu-total">
                                    <span>{{
                                        t('listing.inquiry.orderTotal', {
                                            count: orderItemCount,
                                        })
                                    }}</span>
                                    <strong>{{
                                        formatPrice(orderTotal)
                                    }}</strong>
                                </p>
                                <InputError :message="errors.items" />
                            </div>

                            <label>
                                {{ t('listing.inquiry.message') }}
                                <textarea name="message" rows="3"></textarea>
                                <InputError :message="errors.message" />
                            </label>
                            <!--
                                type="button" while logged out, so the click
                                opens the login modal instead of firing a
                                request the server would only bounce. The typed
                                values stay in the form and are sent as soon as
                                the modal reports back.
                            -->
                            <button
                                :type="account ? 'submit' : 'button'"
                                class="cta"
                                :disabled="processing || inquiryBlocked"
                                @click="onInquirySend"
                            >
                                {{
                                    processing
                                        ? t('listing.inquiry.sending')
                                        : inquirySendLabel
                                }}
                            </button>
                            <p v-if="!account" class="inquiry-login-note">
                                {{ t('listing.inquiry.loginRequired') }}
                            </p>
                            <p v-if="recentlySuccessful" class="confirm-note">
                                {{ inquirySuccessLabel }}
                            </p>
                        </Form>
                    </template>
                    <p v-else class="inquiry-subtitle">
                        {{ t('listing.inquiry.unavailable') }}
                    </p>
                </div>
            </div>
        </section>

        <section>
            <div class="section-head">
                <h2>{{ t('listing.reviews.title') }}</h2>
            </div>

            <div v-if="props.reviews.length" class="review-list">
                <div
                    v-for="review in props.reviews"
                    :key="review.id"
                    class="review-item"
                >
                    <div class="review-item-head">
                        <span class="review-stars">{{
                            '★'.repeat(review.rating) +
                            '☆'.repeat(5 - review.rating)
                        }}</span>
                        <strong>{{ review.name }}</strong>
                        <span class="review-date">{{ review.created_at }}</span>
                    </div>
                    <p>{{ review.comment }}</p>
                </div>
            </div>
            <p v-else class="inquiry-subtitle">
                {{ t('listing.reviews.empty') }}
            </p>

            <div class="review-form-panel">
                <h3>{{ t('listing.reviews.formTitle') }}</h3>
                <Form
                    v-bind="
                        reviewRoutes.store.form({ listing: props.listing.slug })
                    "
                    reset-on-success
                    v-slot="{ errors, processing, recentlySuccessful }"
                    class="inquiry-form"
                    @success="reviewRatingInput = 0"
                >
                    <label>
                        {{ t('listing.reviews.name') }}
                        <input name="name" type="text" required />
                        <InputError :message="errors.name" />
                    </label>
                    <div class="star-picker">
                        <span>{{ t('listing.reviews.rating') }}</span>
                        <div
                            class="star-picker-stars"
                            @mouseleave="reviewRatingHover = 0"
                        >
                            <button
                                v-for="n in 5"
                                :key="n"
                                type="button"
                                class="star-picker-star"
                                :class="{
                                    filled:
                                        (reviewRatingHover ||
                                            reviewRatingInput) >= n,
                                }"
                                @mouseenter="reviewRatingHover = n"
                                @click="reviewRatingInput = n"
                            >
                                ★
                            </button>
                        </div>
                        <input
                            name="rating"
                            type="hidden"
                            :value="reviewRatingInput || ''"
                        />
                        <InputError :message="errors.rating" />
                    </div>
                    <label>
                        {{ t('listing.reviews.comment') }}
                        <textarea name="comment" rows="3" required></textarea>
                        <InputError :message="errors.comment" />
                    </label>
                    <button
                        type="submit"
                        class="cta"
                        :disabled="processing || reviewRatingInput === 0"
                    >
                        {{
                            processing
                                ? t('listing.reviews.sending')
                                : t('listing.reviews.send')
                        }}
                    </button>
                    <p v-if="recentlySuccessful" class="confirm-note">
                        {{ t('listing.reviews.success') }}
                    </p>
                </Form>
            </div>
        </section>

        <!-- Opened by the inquiry form's send button while logged out. -->
        <SaveLoginModal
            v-if="showInquiryLogin"
            intent="book"
            @close="showInquiryLogin = false"
            @authenticated="onInquiryAuthenticated"
        />

        <SiteFooter />
        <MobileFooterNav />
    </div>
</template>

<style scoped>
.photo-attribution {
    max-width: 1040px;
    margin: -12px auto 24px;
    padding: 0 24px;
    font-size: 12px;
    color: #8a8171;
}

.contact-address {
    margin: -4px 0 12px;
    color: var(--ink-light, #5c5347);
    font-size: 14px;
}

.location-card :deep(.explore-map-wrapper) {
    margin: 0;
}

.detail-directions-link {
    display: inline-block;
    margin-top: 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--rust, #b45309);
    text-decoration: underline;
}

.gallery-thumb-btn {
    padding: 0;
    border: none;
    background: none;
    cursor: pointer;
    display: block;
}

.listing-description {
    /* Plain-text descriptions (AI-generated, Wetu import, partner-entered) come as
       multiple paragraphs separated by blank lines with no markup — pre-line
       preserves those line breaks (still wraps normally, still collapses runs of
       spaces) instead of the browser default of flattening every newline into one
       continuous block of text. Rich-text descriptions from the admin editor bring
       their own <p> tags, which pre-line leaves alone. */
    white-space: pre-line;
}

.listing-description :deep(p) {
    margin: 0 0 1em;
}

.listing-description :deep(p:last-child) {
    margin-bottom: 0;
}

.listing-description :deep(ul),
.listing-description :deep(ol) {
    margin: 0 0 1em;
    padding-left: 1.25em;
}

.listing-description :deep(a) {
    color: inherit;
    text-decoration: underline;
}

.draft-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: #92400e;
    color: #fef3c7;
    padding: 8px 12px;
    font-size: 14px;
    font-weight: 600;
}

.draft-banner-badge {
    background: rgba(0, 0, 0, 0.25);
    border: 1px solid rgba(254, 243, 199, 0.6);
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 13px;
}

.draft-banner-publish {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 3px 12px 3px 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.draft-banner-publish:hover {
    background: #15803d;
}

.pending-photos-preview {
    background: #1e3a5f;
    color: #dbeafe;
    padding: 10px 12px;
    font-size: 13px;
}

.pending-photos-preview p {
    margin: 0 0 8px;
}

.pending-photos-thumbs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
}

.pending-photos-thumbs img {
    height: 56px;
    width: 56px;
    object-fit: cover;
    border-radius: 6px;
    flex-shrink: 0;
}

.owner-header-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    white-space: nowrap;
}

.owner-header-link--edit {
    background: #f3ede0;
    color: var(--ink, #1a1a1a);
}

.owner-header-link--edit:hover {
    background: #e9dfc8;
}

.owner-header-link--publish {
    background: var(--rust, #b45309);
    color: #fff;
}

.owner-header-link--publish:hover {
    background: var(--rust-dark, #92400e);
}

.owner-header-link--claim {
    background: transparent;
    color: var(--rust, #b45309);
    border: 1px solid var(--rust, #b45309);
}

.owner-header-link--claim:hover {
    background: #fdf6ec;
}

.detail-mobile-owner-bar {
    display: none;
}
@media (hover: none), (pointer: coarse) {
    .detail-mobile-owner-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        max-width: 1040px;
        margin: 0 auto;
        padding: 10px 24px;
        background: var(--paper, #fbf8f1);
        border-bottom: 1px solid var(--sand-dark, #d6c9b5);
    }
}

/* --- Restaurant: table or order ------------------------------------------ */

.inquiry-mode-tabs {
    display: flex;
    gap: 4px;
    margin: 0 0 14px;
    padding: 4px;
    background: var(--sand, #efe6d6);
    border-radius: 999px;
}

.inquiry-mode-tabs button {
    flex: 1;
    padding: 9px 12px;
    border: none;
    border-radius: 999px;
    background: none;
    color: var(--ink-light, #5c5347);
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    /* Both labels are on screen at once, so the tab must not resize when the
       weight changes on selection — the row would shuffle under the thumb. */
    transition:
        background 0.15s ease,
        color 0.15s ease;
}

.inquiry-mode-tabs button.active {
    background: var(--paper, #fbf8f1);
    color: var(--ink, #2f2a22);
    box-shadow: 0 1px 3px rgb(47 42 34 / 0.12);
}

.menu-picker {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.menu-section h4 {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-light, #5c5347);
}

.menu-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 12px;
    background: var(--paper, #fbf8f1);
}

.menu-item + .menu-item {
    margin-top: 8px;
}

.menu-item.picked {
    border-color: var(--rust, #b45309);
    background: #fdf6ec;
}

.menu-item-photo {
    width: 48px;
    height: 48px;
    flex: none;
    border-radius: 8px;
    object-fit: cover;
}

.menu-item-text {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
    font-size: 14px;
}

.menu-item-description {
    font-size: 12px;
    color: var(--ink-light, #5c5347);
}

.menu-item-price {
    font-size: 13px;
    font-weight: 600;
    color: var(--rust, #b45309);
}

.menu-item-stepper {
    display: flex;
    flex: none;
    align-items: center;
    gap: 4px;
}

.menu-item-stepper button {
    /* 36px rather than the visual 28px: this is the control a phone user taps
       repeatedly, and a stepper that misses is worse than no stepper. */
    width: 36px;
    height: 36px;
    border: 1px solid var(--sand-dark, #d6c9b5);
    border-radius: 50%;
    background: var(--paper, #fbf8f1);
    color: var(--ink, #2f2a22);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
}

.menu-item-stepper button:disabled {
    opacity: 0.35;
    cursor: default;
}

.menu-item-quantity {
    min-width: 20px;
    text-align: center;
    font-size: 14px;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

/* The card as something to read, not to order from. Deliberately quieter than
   the order picker: no borders per line, no controls, just the list. */
.menu-card {
    margin-top: 28px;
}

.menu-card h3 {
    margin: 0 0 4px;
}

.menu-card-note {
    margin: 0 0 18px;
    font-size: 13px;
    color: var(--ink-light, #5c5347);
}

.menu-card-section + .menu-card-section {
    margin-top: 20px;
}

.menu-card-section h4 {
    margin: 0 0 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--sand-dark, #d6c9b5);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-light, #5c5347);
}

.menu-card-item {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    padding: 7px 0;
}

.menu-card-item-text {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 2px;
    font-size: 15px;
}

.menu-card-item-price {
    flex: none;
    font-size: 14px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: var(--rust, #b45309);
}

.menu-total {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin: 0;
    padding-top: 12px;
    border-top: 1px solid var(--sand-dark, #d6c9b5);
    font-size: 15px;
}
</style>
