<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import CompanyDetails from '@/components/CompanyDetails.vue';
import PublicNav from '@/components/PublicNav.vue';

/**
 * The public price list. Same plans and packs the employer's own
 * /subscription and /employer/ai-credits pages sell; "Buy" hands over to
 * those pages, so checkout, coupons and the GST invoice live in one place.
 */
interface Plan {
    id: number;
    name: string;
    tagline: string | null;
    type: 'job' | 'database';
    price: number;
    mrp: number | null;
    discount_percent: number | null;
    price_with_gst: number;
    interval_label: string;
    featured: boolean;
    feature_list: string[];
}

interface Pack {
    id: number;
    name: string;
    price: number;
    price_with_gst: number;
    credits: number;
    bonus_credits: number;
    total_credits: number;
    minutes: number;
    popular: boolean;
}

const props = defineProps<{
    plans: Plan[];
    packs: Pack[];
    creditRules: string[];
    gstPercent: number;
    viewer: 'guest' | 'employer' | 'other';
    canBuyCredits: boolean;
}>();

const sections = computed(() =>
    [
        {
            id: 'job-plans',
            label: 'Hire',
            title: 'Job plans',
            lede: 'Post jobs, get applicants from your area, and let the AI rank them so the best karigar is on top.',
            plans: props.plans.filter((p) => p.type !== 'database'),
        },
        {
            id: 'database-plans',
            label: 'Search',
            title: 'Worker Database',
            lede: 'Skip the wait. Search verified karigars by craft and location and call them directly.',
            plans: props.plans.filter((p) => p.type === 'database'),
        },
    ].filter((section) => section.plans.length),
);

// Guests sign in (or sign up) with their mobile first and come back to the
// checkout of the plan they picked; a signed-in employer goes straight there.
const buyHref = (target: string) => (props.viewer === 'employer' ? target : `/employer/login?redirect=${encodeURIComponent(target)}`);

// Whole rupees as "₹999", paise always to two places: "₹1,178.82".
const money = (n: number) =>
    '₹' + n.toLocaleString('en-IN', Number.isInteger(n) ? {} : { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n: number) => n.toLocaleString('en-IN');

const faqs = computed(() => [
    {
        q: 'How do I buy a plan?',
        a: 'Press Buy, enter your mobile number and the OTP we send. If you are new, that same step creates your employer account. You land on the checkout for the plan you picked and pay through Razorpay — UPI, cards or net banking.',
    },
    {
        q: 'Job plan or Worker Database — which one do I need?',
        a: 'A job plan is for posting jobs and receiving applicants. The Worker Database is for finding karigars yourself and calling them. You can hold one of each at the same time, and every job plan already opens part of the database.',
    },
    {
        q: 'How long does a job stay live?',
        a: 'Each job stays live for the number of days written on its plan, counted from the moment it goes live. When it ends, repost it from My Jobs — that uses one more job post from your plan.',
    },
    {
        q: 'Does the plan renew on its own?',
        a: 'Yes. Plans renew every cycle through Razorpay, so your jobs keep running without a gap. To stop a renewal, write to us at the address at the bottom of this page.',
    },
    ...(props.gstPercent > 0
        ? [
              {
                  q: 'Is GST included?',
                  a: `Prices are shown before GST; ${props.gstPercent}% GST is added at checkout. Every payment, including each renewal, comes with a GST tax invoice by email — add your GSTIN in your profile to claim input credit.`,
              },
          ]
        : []),
    ...(props.packs.length
        ? [
              {
                  q: 'What are AI call credits?',
                  a: 'When AI calling is on for a job, our voice agent rings shortlisted applicants, asks your screening questions and books an interview slot. Credits pay for those minutes. They never expire, and calls that are not picked up cost nothing.',
              },
          ]
        : []),
    {
        q: 'Have a coupon code?',
        a: 'Apply it on the checkout screen, after you press Buy. The discount and the GST on the discounted price are shown before you pay.',
    },
]);
</script>

<template>
    <Head title="Pricing for employers — Super Karigar">
        <meta
            name="description"
            content="Super Karigar plans for employers: job posting plans, Worker Database access and AI call credits. Prices in rupees, GST invoice with every payment."
        />
    </Head>

    <div class="theme-paper bg-noise min-h-screen bg-background text-foreground antialiased">
        <PublicNav>
            <Link href="/jobs" class="link-underline">{{ $t('nav.browseJobs') }}</Link>
            <a href="#job-plans" class="link-underline">Job plans</a>
            <a href="#database-plans" class="link-underline">Worker Database</a>
            <a v-if="packs.length" href="#ai-credits" class="link-underline">AI calls</a>
            <a href="#faq" class="link-underline">FAQ</a>
        </PublicNav>

        <!-- Masthead -->
        <section class="border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-14 lg:px-10 lg:py-20">
                <div class="flex items-center gap-3 text-primary">
                    <span class="h-px w-10 bg-primary"></span>
                    <span class="label-rule">Pricing · For employers</span>
                </div>
                <h1 class="mt-6 max-w-4xl text-4xl leading-[1.05] font-semibold tracking-tight lg:text-6xl">
                    Pay for the hiring you do. Nothing hidden.
                </h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                    Pick a plan, sign in with your mobile number and pay — your plan starts the moment the payment goes through.
                    <template v-if="gstPercent > 0">Prices below are before {{ gstPercent }}% GST; you get a GST invoice for every payment.</template>
                </p>
                <p v-if="viewer === 'other'" class="mt-6 max-w-2xl border-l-2 border-primary pl-4 text-sm text-muted-foreground">
                    You are signed in with a karigar account. Plans are bought from an employer account — sign out and sign in as an employer to buy one.
                </p>
            </div>
        </section>

        <!-- Plans: hairline-ruled columns, not cards -->
        <section v-for="section in sections" :id="section.id" :key="section.id" class="scroll-mt-20 border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-14 lg:px-10 lg:py-20">
                <div class="grid gap-6 lg:grid-cols-[16rem_1fr] lg:gap-16">
                    <div>
                        <div class="label-rule text-primary">{{ section.label }}</div>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight">{{ section.title }}</h2>
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ section.lede }}</p>
                    </div>

                    <div
                        class="grid border-t border-foreground/15 sm:grid-cols-2"
                        :class="section.plans.length >= 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'"
                    >
                        <div
                            v-for="plan in section.plans"
                            :key="plan.id"
                            class="relative flex flex-col border-b border-foreground/10 py-8 sm:px-6 sm:odd:pl-0 sm:even:border-l xl:border-b-0 xl:not-first:border-l xl:not-first:pl-6"
                        >
                            <span v-if="plan.featured" class="absolute inset-x-0 -top-px h-0.5 bg-primary"></span>
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <h3 class="text-lg font-semibold">{{ plan.name }}</h3>
                                <span v-if="plan.featured" class="label-rule whitespace-nowrap text-primary">Most popular</span>
                            </div>
                            <p v-if="plan.tagline" class="mt-1 text-sm text-muted-foreground">{{ plan.tagline }}</p>
                            <p v-if="plan.mrp" class="mt-4 flex items-center gap-2 text-sm">
                                <span class="text-muted-foreground line-through">{{ money(plan.mrp) }}</span>
                                <span class="font-semibold text-primary">{{ plan.discount_percent }}% OFF</span>
                            </p>
                            <div :class="plan.mrp ? 'mt-1' : 'mt-5'" class="flex items-end gap-1.5">
                                <span class="text-[2.75rem] leading-none font-semibold tracking-tight tabular-nums">{{ money(plan.price) }}</span>
                                <span class="pb-1 text-sm whitespace-nowrap text-muted-foreground">/ {{ plan.interval_label }}</span>
                            </div>
                            <p v-if="gstPercent > 0" class="mt-1.5 text-xs text-muted-foreground">
                                + {{ gstPercent }}% GST · {{ money(plan.price_with_gst) }} total
                            </p>

                            <ul class="mt-7 flex-1 space-y-3 text-sm">
                                <li v-for="line in plan.feature_list" :key="line" class="flex items-start gap-2.5">
                                    <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                                    <span>{{ line }}</span>
                                </li>
                            </ul>

                            <Link
                                v-if="viewer !== 'other'"
                                :href="buyHref(`/subscription?plan=${plan.id}`)"
                                class="mt-8 inline-flex items-center justify-center gap-2 rounded-sm px-4 py-3 text-sm font-semibold transition"
                                :class="plan.featured ? 'bg-primary text-white hover:bg-foreground' : 'bg-foreground text-background hover:bg-primary'"
                            >
                                Buy {{ plan.name }} <ArrowRight class="size-4" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- AI call credits: prepaid, one-time -->
        <section v-if="packs.length" id="ai-credits" class="scroll-mt-20 border-b border-foreground/10">
            <div class="mx-auto max-w-[88rem] px-6 py-14 lg:px-10 lg:py-20">
                <div class="grid gap-6 lg:grid-cols-[16rem_1fr] lg:gap-16">
                    <div>
                        <div class="label-rule text-primary">Add-on</div>
                        <h2 class="mt-3 text-3xl font-semibold tracking-tight">AI call credits</h2>
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                            Our AI voice agent calls shortlisted applicants in Hindi, screens them and books the interview. Prepaid, one-time — no renewal.
                        </p>
                    </div>

                    <div>
                        <div class="grid border-t border-foreground/15 sm:grid-cols-3">
                            <div
                                v-for="pack in packs"
                                :key="pack.id"
                                class="relative flex flex-col border-b border-foreground/10 py-8 sm:border-b-0 sm:px-6 sm:first:pl-0 sm:not-first:border-l"
                            >
                                <span v-if="pack.popular" class="absolute inset-x-0 -top-px h-0.5 bg-primary"></span>
                                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                    <h3 class="text-lg font-semibold">{{ pack.name }}</h3>
                                    <span v-if="pack.popular" class="label-rule whitespace-nowrap text-primary">Best value</span>
                                </div>
                                <div class="mt-5 text-5xl font-semibold tracking-tight tabular-nums">{{ num(pack.total_credits) }}</div>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    credits · about {{ num(pack.minutes) }} min of calls
                                    <template v-if="pack.bonus_credits > 0"><br /><span class="font-semibold text-primary">incl. {{ num(pack.bonus_credits) }} bonus</span></template>
                                </p>
                                <div class="mt-6 border-t border-foreground/10 pt-4">
                                    <span class="text-2xl font-semibold tabular-nums">{{ money(pack.price) }}</span>
                                    <p v-if="gstPercent > 0" class="mt-0.5 text-xs text-muted-foreground">+ GST · {{ money(pack.price_with_gst) }} total</p>
                                </div>
                                <Link
                                    v-if="viewer !== 'other' && canBuyCredits"
                                    :href="buyHref(`/employer/ai-credits?pack=${pack.id}`)"
                                    class="mt-6 inline-flex items-center justify-center gap-2 rounded-sm px-4 py-3 text-sm font-semibold transition"
                                    :class="pack.popular ? 'bg-primary text-white hover:bg-foreground' : 'bg-foreground text-background hover:bg-primary'"
                                >
                                    Buy {{ pack.name }} <ArrowRight class="size-4" />
                                </Link>
                            </div>
                        </div>
                        <p v-if="viewer === 'employer' && !canBuyCredits" class="mt-4 text-sm text-muted-foreground">
                            Only the account owner can buy credits for your team.
                        </p>
                        <ul class="mt-8 grid gap-x-10 gap-y-2 border-t border-foreground/10 pt-6 text-sm text-muted-foreground sm:grid-cols-2">
                            <li v-for="rule in creditRules" :key="rule" class="flex items-start gap-2.5">
                                <span class="mt-2 h-px w-3 shrink-0 bg-primary"></span>
                                <span>{{ rule }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="scroll-mt-20 border-b border-foreground/10">
            <div class="mx-auto grid max-w-[88rem] gap-6 px-6 py-14 lg:grid-cols-[16rem_1fr] lg:gap-16 lg:px-10 lg:py-20">
                <div>
                    <div class="label-rule text-primary">Questions</div>
                    <h2 class="mt-3 text-3xl font-semibold tracking-tight">Before you pay</h2>
                </div>
                <dl class="divide-y divide-foreground/10 border-t border-foreground/15">
                    <div v-for="item in faqs" :key="item.q" class="grid gap-2 py-6 md:grid-cols-[minmax(0,20rem)_1fr] md:gap-10">
                        <dt class="font-semibold">{{ item.q }}</dt>
                        <dd class="leading-relaxed text-muted-foreground">{{ item.a }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        <!-- Closing ink block -->
        <section class="bg-foreground text-background">
            <div class="mx-auto flex max-w-[88rem] flex-col items-start justify-between gap-8 px-6 py-16 lg:flex-row lg:items-end lg:px-10 lg:py-20">
                <div>
                    <h2 class="max-w-2xl text-3xl leading-tight font-semibold tracking-tight lg:text-5xl">Not sure yet? Look around first.</h2>
                    <p class="mt-4 max-w-xl text-background/70">Create your employer account for free, set up your profile and see karigars near you before you buy.</p>
                </div>
                <Link
                    :href="viewer === 'guest' ? '/employer/register' : '/dashboard'"
                    class="inline-flex shrink-0 items-center gap-2 rounded-sm bg-primary px-6 py-3.5 text-sm font-semibold text-white transition hover:bg-background hover:text-foreground"
                >
                    {{ viewer === 'guest' ? 'Create employer account' : $t('nav.dashboard') }} <ArrowRight class="size-4" />
                </Link>
            </div>
        </section>

        <footer class="bg-foreground text-background/70">
            <div class="mx-auto flex max-w-[88rem] flex-wrap items-center justify-between gap-4 border-t border-background/15 px-6 py-10 text-sm lg:px-10">
                <div class="text-[18px] text-background">
                    <BrandWordmark tone="plain" />
                </div>
                <div class="flex flex-wrap gap-6">
                    <Link href="/" class="transition hover:text-background">Home</Link>
                    <Link href="/jobs" class="transition hover:text-background">{{ $t('nav.browseJobs') }}</Link>
                    <Link href="/pricing" class="text-background">Pricing</Link>
                    <Link href="/privacy" class="transition hover:text-background">Privacy</Link>
                </div>
            </div>
            <div class="mx-auto max-w-[88rem] border-t border-background/15 px-6 py-6 lg:px-10">
                <CompanyDetails variant="line" />
            </div>
        </footer>
    </div>
</template>
