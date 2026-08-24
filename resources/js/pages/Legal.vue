<script setup lang="ts">
import '../../css/kaia-home.css';
import { Head, Link } from '@inertiajs/vue3';
import { home, terms } from '@/routes';
import logoDark from '../../images/logo-dark.png';

/** One picture's provenance, as recorded next to the picture itself. */
interface ImageCredit {
    title: string;
    photographer: string | null;
    license: string | null;
    source: string | null;
}

interface Operator {
    name: string;
    legal_form: string | null;
    address: string[];
    country: string | null;
    represented_by: string[];
    register: string | null;
    registration_number: string | null;
    vat_id: string | null;
    email: string | null;
    phone: string | null;
    content_responsible: string | null;
    notes: string | null;
}

// `operator` is null until somebody fills config/legal.php in. The page then
// says so, rather than printing a legal notice with its middle missing.
defineProps<{
    operator: Operator | null;
    copyright: { holder: string; year: number };
    imageCredits: ImageCredit[];
}>();
</script>

<template>
    <Head title="Legal notice" />

    <div class="kaia-page">
        <div class="detail-topbar">
            <Link :href="home()" class="brand"
                ><img :src="logoDark" alt="NamibWay" class="brand-logo"
            /></Link>
            <Link :href="home()" class="detail-back">Back to home</Link>
        </div>

        <div class="legal-page">
            <h1>Legal notice</h1>

            <section>
                <h2>Who operates this site</h2>

                <template v-if="operator">
                    <p class="legal-operator">
                        <strong>{{ operator.name }}</strong>
                        <template v-if="operator.legal_form">
                            <br />{{ operator.legal_form }}
                        </template>
                        <template v-for="line in operator.address" :key="line">
                            <br />{{ line }}
                        </template>
                        <template v-if="operator.country">
                            <br />{{ operator.country }}
                        </template>
                    </p>

                    <dl class="legal-facts">
                        <template v-if="operator.represented_by.length">
                            <dt>Represented by</dt>
                            <dd>{{ operator.represented_by.join(', ') }}</dd>
                        </template>
                        <template v-if="operator.register">
                            <dt>Register</dt>
                            <dd>{{ operator.register }}</dd>
                        </template>
                        <template v-if="operator.registration_number">
                            <dt>Registration number</dt>
                            <dd>{{ operator.registration_number }}</dd>
                        </template>
                        <template v-if="operator.vat_id">
                            <dt>VAT identification number</dt>
                            <dd>{{ operator.vat_id }}</dd>
                        </template>
                        <template v-if="operator.email">
                            <dt>Email</dt>
                            <dd>
                                <a :href="`mailto:${operator.email}`">{{
                                    operator.email
                                }}</a>
                            </dd>
                        </template>
                        <template v-if="operator.phone">
                            <dt>Telephone</dt>
                            <dd>{{ operator.phone }}</dd>
                        </template>
                        <template v-if="operator.content_responsible">
                            <dt>Responsible for the content</dt>
                            <dd>{{ operator.content_responsible }}</dd>
                        </template>
                    </dl>

                    <p v-if="operator.notes">{{ operator.notes }}</p>
                </template>

                <div v-else class="legal-pending">
                    The operator's details have not been published here yet.
                    They are being prepared and will appear on this page.
                </div>
            </section>

            <section>
                <h2>Copyright</h2>

                <p>
                    The design, the written copy and the software of
                    namibway.com are &copy; {{ copyright.year }}
                    {{ copyright.holder }}. NamibWay and Kaia are our names for
                    this platform and its travel companion.
                </p>

                <p>
                    Listings do not all come from one place, and which place a
                    given listing came from is recorded against it. A
                    description or a photograph is either supplied by the
                    partner or property owner, taken from the property's own
                    website, written by us from the property's own facts, or
                    obtained through Google Places. Where the source asks for
                    it, the attribution is shown on the listing itself rather
                    than only here.
                </p>

                <p>
                    Material from third-party travel directories is used
                    internally as reference while we match and verify
                    properties. It is not published: editing somebody else's
                    text does not make it ours.
                </p>

                <p v-if="operator?.email">
                    If you hold rights in something published here and believe
                    it should not be, write to
                    <a :href="`mailto:${operator.email}`">{{
                        operator.email
                    }}</a>
                    with a link to the page. We would rather take something down
                    while we check than leave it up while we argue.
                </p>
            </section>

            <section v-if="imageCredits.length">
                <h2>Photographs</h2>

                <p>
                    The photograph behind the homepage changes from day to day.
                    These are the pictures in that rotation and anything else on
                    the site whose photographer we know, with the licence we
                    hold each one under and a link to where it can be checked.
                </p>

                <ul class="legal-credits">
                    <li v-for="credit in imageCredits" :key="credit.title">
                        <span class="legal-credit-title">{{
                            credit.title
                        }}</span>
                        <span class="legal-credit-meta">
                            <template v-if="credit.photographer"
                                >Photograph by
                                {{ credit.photographer }}</template
                            >
                            <template v-if="credit.license">
                                &middot; {{ credit.license }}</template
                            >
                            <template v-if="credit.source">
                                &middot;
                                <a
                                    :href="credit.source"
                                    rel="noopener noreferrer nofollow"
                                    target="_blank"
                                    >Source</a
                                >
                            </template>
                        </span>
                    </li>
                </ul>
            </section>

            <section>
                <h2>Terms</h2>
                <p>
                    The terms that apply to publishing a listing are on the
                    <Link :href="terms()">Terms &amp; Conditions</Link> page.
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.legal-page {
    max-width: 680px;
    margin: 32px auto 64px;
    padding: 0 24px;
}

.legal-page h1 {
    font-family: 'Fraunces', serif;
    font-size: 28px;
    margin: 0 0 24px;
}

.legal-page h2 {
    font-family: 'Fraunces', serif;
    font-size: 19px;
    margin: 0 0 12px;
}

.legal-page section {
    margin-bottom: 34px;
}

.legal-page p {
    font-size: 14px;
    line-height: 1.7;
    color: #4a4438;
    margin-bottom: 14px;
}

.legal-page a {
    color: var(--rust-dark);
    text-decoration: underline;
}

.legal-operator {
    line-height: 1.6;
}

/* Label above value rather than beside it: a register entry or a VAT number
   is long, the labels are not, and a two-column grid on a phone turns every
   one of them into three wrapped lines. */
.legal-facts {
    margin: 0 0 14px;
    font-size: 14px;
    line-height: 1.6;
    color: #4a4438;
}

.legal-facts dt {
    font-weight: 600;
    color: var(--ink);
    margin-top: 10px;
}

.legal-facts dd {
    margin: 0;
}

.legal-credits {
    list-style: none;
    margin: 0;
    padding: 0;
}

.legal-credits li {
    padding: 10px 0;
    border-top: 1px solid var(--sand-dark);
    font-size: 14px;
    line-height: 1.6;
}

.legal-credit-title {
    display: block;
    color: var(--ink);
}

.legal-credit-meta {
    display: block;
    font-size: 13px;
    color: #6f6553;
}

.legal-pending {
    background: #fef3c7;
    color: #92400e;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    font-weight: 600;
}
</style>
