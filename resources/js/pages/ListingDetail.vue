<script setup lang="ts">
import '../../css/kaia-home.css';
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
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
    region: string | null;
    price_from: string | null;
    price_currency: string;
    rating: number | null;
    rating_count: number | null;
    accepts_inquiries: boolean;
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
}>();

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
</script>

<template>
    <Head :title="props.listing.name" />

    <div class="kaia-page">
        <div class="detail-topbar">
            <Link :href="home()" class="brand"
                ><img :src="logoDark" alt="NamibWay" class="brand-logo"
            /></Link>
            <div style="display: flex; align-items: center; gap: 8px">
                <LocaleSwitcher />
                <Link :href="home()" class="detail-back">{{
                    t('listing.backToHome')
                }}</Link>
            </div>
        </div>

        <div
            class="detail-hero"
            :style="{ backgroundImage: `url(${heroImage})` }"
        >
            <div class="detail-hero-overlay">
                <span class="idea-tag">{{
                    t(`listing.types.${props.listing.type}`)
                }}</span>
                <h1>{{ props.listing.name }}</h1>
                <p v-if="props.listing.region">{{ props.listing.region }}</p>
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
                <p v-if="props.listing.price_from" class="detail-price">
                    {{ t('listing.from') }} {{ props.listing.price_currency }}
                    {{ props.listing.price_from }}
                </p>
            </div>
        </div>

        <section v-if="props.listing.gallery.length">
            <div class="section-head">
                <h2>{{ t('listing.gallery') }}</h2>
            </div>
            <div class="detail-gallery">
                <img
                    v-for="(src, i) in props.listing.gallery"
                    :key="i"
                    :src="src"
                    :alt="`${props.listing.name} ${i + 1}`"
                    loading="lazy"
                />
            </div>
        </section>

        <section>
            <div class="detail-grid">
                <div>
                    <div v-if="props.listing.description" class="section-head">
                        <h2>{{ t('listing.about') }}</h2>
                        <p>{{ props.listing.description }}</p>
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

                    <div v-if="props.listing.partner" class="contact-card">
                        <h3>{{ t('listing.contact.title') }}</h3>
                        <p class="contact-partner-name">
                            {{ props.listing.partner.name }}
                        </p>
                        <div class="contact-links">
                            <a
                                v-if="props.listing.partner.website"
                                :href="props.listing.partner.website"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.website') }}</a
                            >
                            <a
                                v-if="props.listing.partner.instagram"
                                :href="props.listing.partner.instagram"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.instagram') }}</a
                            >
                            <a
                                v-if="props.listing.partner.facebook"
                                :href="props.listing.partner.facebook"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ t('listing.contact.facebook') }}</a
                            >
                        </div>
                    </div>
                </div>

                <div class="inquiry-panel">
                    <template v-if="props.listing.accepts_inquiries">
                        <h3>{{ t('listing.inquiry.title') }}</h3>
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
                            <label>
                                {{ t('listing.inquiry.travelDates') }}
                                <input name="travel_dates" type="text" />
                                <InputError :message="errors.travel_dates" />
                            </label>
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
                    v-bind="reviewRoutes.store.form({ listing: props.listing.slug })"
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
                                    filled: (reviewRatingHover || reviewRatingInput) >= n,
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
