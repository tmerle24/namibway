<script setup lang="ts">
import '../../css/kaia-home.css';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Globe, Pencil, UserPlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminBar from '@/components/AdminBar.vue';
import CurrencySwitcher from '@/components/CurrencySwitcher.vue';
import ExploreMap from '@/components/home/ExploreMap.vue';
import type { ExploreMapMarker } from '@/components/home/ExploreMap.vue';
import ImageLightbox from '@/components/ImageLightbox.vue';
import InputError from '@/components/InputError.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import PublishConsentModal from '@/components/PublishConsentModal.vue';
import { formatPrice } from '@/lib/currency';
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
    photos_source: 'manual' | 'website_scrape' | 'google_places' | null;
    photos_attribution: string | null;
    pending_image: string | null;
    pending_gallery: string[];
    region: string | null;
    address: string | null;
    phone: string | null;
    phone_href: string | null;
    website: string | null;
    latitude: number | null;
    longitude: number | null;
    price_from: string | null;
    price_currency: string;
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

const props = defineProps<{
    listing: Listing;
    reviews: Review[];
    is_preview?: boolean;
    can_publish?: boolean;
    can_approve_photos?: boolean;
    preview_token?: string | null;
    claim_url?: string | null;
}>();

const showPublishModal = ref(false);
const lightboxIndex = ref<number | null>(null);
const pendingPhotoCount = computed(
    () =>
        props.listing.pending_gallery.length +
        (props.listing.pending_image ? 1 : 0),
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

const { t } = useI18n();

const reviewRatingInput = ref(0);
const reviewRatingHover = ref(0);

const heroImage = computed(() => {
    if (props.listing.image) {
        return props.listing.image;
    }

    const fallbacks = FALLBACK_HERO_IMAGES[props.listing.type];

    return fallbacks[props.listing.id % fallbacks.length];
});

// Carries forward whatever explore filters were active when this listing
// was opened (see ExploreSection.vue's listingUrl()), so "back" restores
// the previous search instead of dropping the user on a blank homepage.
const FORWARDED_FILTER_KEYS = [
    'type',
    'region',
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
                    :src="props.listing.pending_image"
                    alt="Pending hero image"
                />
                <img
                    v-for="(src, i) in props.listing.pending_gallery"
                    :key="i"
                    :src="src"
                    :alt="`Pending gallery image ${i + 1}`"
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
                    :src="props.listing.pending_image"
                    alt="Pending hero image"
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
                    :src="src"
                    :alt="`Pending gallery image ${i + 1}`"
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
                <CurrencySwitcher />
                <LocaleSwitcher />
                <Link :href="backHref" class="detail-back">{{
                    t('listing.backToHome')
                }}</Link>
            </div>
        </div>

        <div
            class="detail-hero"
            :style="{ backgroundImage: `url(${heroImage})` }"
        >
            <div class="detail-hero-overlay">
                <Link
                    :href="home({ query: { type: props.listing.type } })"
                    class="idea-tag"
                    >{{ t(`listing.types.${props.listing.type}`) }}</Link
                >
                <h1>{{ props.listing.name }}</h1>
                <p v-if="props.listing.region">
                    <Link
                        :href="
                            home({ query: { region: props.listing.region } })
                        "
                        class="detail-region-link"
                        >{{ props.listing.region }}</Link
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
                <p
                    v-if="formatPrice(props.listing.price_from)"
                    class="detail-price"
                >
                    {{ t('listing.from') }}
                    {{ formatPrice(props.listing.price_from) }}
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
                        :src="src"
                        :alt="`${props.listing.name} ${i + 1}`"
                        loading="lazy"
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
                            <a
                                v-if="props.listing.partner?.instagram"
                                :href="props.listing.partner.instagram"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.instagram') }}</a
                            >
                            <a
                                v-if="props.listing.partner?.facebook"
                                :href="props.listing.partner.facebook"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.facebook') }}</a
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
                        <h3>{{ t('listing.inquiry.title') }}</h3>
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
                        <Form
                            v-bind="
                                inquiries.store.form({
                                    listing: props.listing.slug,
                                })
                            "
                            reset-on-success
                            v-slot="{ errors, processing, recentlySuccessful }"
                            class="inquiry-form"
                        >
                            <label>
                                {{ t('listing.inquiry.name') }}
                                <input name="name" type="text" required />
                                <InputError :message="errors.name" />
                            </label>
                            <label>
                                {{ t('listing.inquiry.email') }}
                                <input name="email" type="email" required />
                                <InputError :message="errors.email" />
                            </label>
                            <label>
                                {{ t('listing.inquiry.phone') }}
                                <input name="phone" type="text" />
                                <InputError :message="errors.phone" />
                            </label>
                            <div class="inquiry-dates-row">
                                <label class="inquiry-date-field">
                                    {{ t('listing.inquiry.checkIn') }}
                                    <input
                                        name="check_in"
                                        type="date"
                                        :min="
                                            new Date()
                                                .toISOString()
                                                .slice(0, 10)
                                        "
                                    />
                                    <InputError :message="errors.check_in" />
                                </label>
                                <label class="inquiry-date-field">
                                    {{ t('listing.inquiry.checkOut') }}
                                    <input
                                        name="check_out"
                                        type="date"
                                        :min="
                                            new Date()
                                                .toISOString()
                                                .slice(0, 10)
                                        "
                                    />
                                    <InputError :message="errors.check_out" />
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
                                    <InputError :message="errors.children" />
                                </label>
                            </div>
                            <label>
                                {{ t('listing.inquiry.message') }}
                                <textarea name="message" rows="3"></textarea>
                                <InputError :message="errors.message" />
                            </label>
                            <button
                                type="submit"
                                class="cta"
                                :disabled="processing"
                            >
                                {{
                                    processing
                                        ? t('listing.inquiry.sending')
                                        : t('listing.inquiry.send')
                                }}
                            </button>
                            <p v-if="recentlySuccessful" class="confirm-note">
                                {{ t('listing.inquiry.success') }}
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

        <footer>
            <img :src="logoDark" alt="NamibWay" class="footer-logo" />
            <p>{{ t('footer.tagline') }}</p>
        </footer>
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
</style>
