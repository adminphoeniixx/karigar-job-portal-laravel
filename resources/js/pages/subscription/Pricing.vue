<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, CreditCard, Sparkles, Tag, X } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: string;
    interval: string;
    features: { job_post_limit?: number; contact_unlock_limit?: number; featured?: boolean } | null;
    razorpay_plan_id: string | null;
}

interface CouponResult {
    code: string;
    valid: boolean;
    message: string | null;
    discount_type?: 'percent' | 'flat';
    discount_value?: number;
    max_discount_amount?: number | null;
    min_amount?: number | null;
    plan_ids?: number[];
}

const props = defineProps<{
    plans: Plan[];
    current: { id: number; status: string; plan: Plan } | null;
    razorpayConfigured: boolean;
    couponResult: CouponResult | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Subscription', href: '/subscription' }] } });

const code = ref(props.couponResult?.code ?? '');
const applying = ref(false);

const applyCoupon = () => {
    if (!code.value.trim()) return;
    applying.value = true;
    router.get(
        '/subscription',
        { coupon: code.value.trim() },
        {
            only: ['couponResult'],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => (applying.value = false),
        },
    );
};

const clearCoupon = () => {
    code.value = '';
    router.get('/subscription', {}, { only: ['couponResult'], preserveState: true, preserveScroll: true });
};

// Discount this coupon yields for a specific plan (0 = not applicable).
const discountFor = (plan: Plan): number => {
    const c = props.couponResult;
    if (!c || !c.valid) return 0;
    const price = parseFloat(plan.price);
    if (c.plan_ids && c.plan_ids.length && !c.plan_ids.includes(plan.id)) return 0;
    if (c.min_amount != null && price < c.min_amount) return 0;
    let d = c.discount_type === 'percent' ? (price * (c.discount_value ?? 0)) / 100 : c.discount_value ?? 0;
    if (c.max_discount_amount != null) d = Math.min(d, c.max_discount_amount);
    return Math.round(Math.min(d, price) * 100) / 100;
};

const finalPrice = (plan: Plan): number => parseFloat(plan.price) - discountFor(plan);

const money = (n: number) => '₹' + n.toLocaleString('en-IN');

const subscribe = (plan: Plan) => {
    // Only send the coupon when it actually discounts this plan (server re-validates).
    const payload = discountFor(plan) > 0 && props.couponResult?.valid ? { coupon: props.couponResult.code } : {};
    router.post(`/subscription/${plan.id}/subscribe`, payload);
};
</script>

<template>
    <Head title="Subscription" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="CreditCard" title="Plans" description="Choose a plan to start posting jobs" />

        <div v-if="current" class="flex items-center gap-2 rounded-2xl border bg-teal-500/5 px-5 py-4">
            <span class="flex size-9 items-center justify-center rounded-full bg-teal-500/15 text-teal-600 dark:text-teal-300"><Check class="size-5" /></span>
            <div class="text-sm">
                Active plan: <strong>{{ current.plan.name }}</strong>
                <span class="ml-2 inline-flex items-center rounded-full bg-teal-500/10 px-2 py-0.5 text-xs font-semibold capitalize text-teal-600 ring-1 ring-inset ring-teal-500/20 dark:text-teal-300">{{ current.status }}</span>
            </div>
        </div>

        <div v-if="!razorpayConfigured" class="flex items-start gap-3 rounded-2xl border border-amber-400/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <span>Razorpay test keys are not configured yet. Plans are shown, but checkout will work once the keys are added to <code class="rounded bg-amber-500/20 px-1">.env</code>.</span>
        </div>

        <!-- Coupon -->
        <div class="rounded-2xl border bg-card p-4 shadow-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="flex size-9 items-center justify-center rounded-xl bg-teal-500/10 text-teal-600 dark:text-teal-300"><Tag class="size-4" /></span>
                <input
                    v-model="code"
                    placeholder="Have a coupon code?"
                    class="h-[42px] flex-1 rounded-xl border bg-background px-4 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-teal-500/40"
                    @keyup.enter="applyCoupon"
                />
                <button
                    type="button"
                    :disabled="applying || !code.trim()"
                    class="inline-flex h-[42px] items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                    @click="applyCoupon"
                >
                    Apply
                </button>
                <button
                    v-if="couponResult"
                    type="button"
                    class="inline-flex h-[42px] items-center gap-1 rounded-xl border px-3 text-sm text-muted-foreground transition hover:bg-muted"
                    @click="clearCoupon"
                >
                    <X class="size-4" /> Clear
                </button>
            </div>
            <p v-if="couponResult && couponResult.valid" class="mt-2 flex items-center gap-1.5 text-sm font-medium text-teal-600 dark:text-teal-400">
                <Check class="size-4" /> Coupon <strong class="mx-1">{{ couponResult.code }}</strong> applied. Eligible plans show the discounted price below.
            </p>
            <p v-else-if="couponResult && !couponResult.valid" class="mt-2 flex items-center gap-1.5 text-sm font-medium text-rose-500">
                <AlertTriangle class="size-4" /> {{ couponResult.message }}
            </p>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="plan in plans"
                :key="plan.id"
                class="relative flex flex-col overflow-hidden rounded-3xl border bg-card p-6 shadow-sm transition hover:shadow-md"
                :class="plan.features?.featured ? 'border-teal-500/40 ring-2 ring-teal-500/20' : ''"
            >
                <div v-if="plan.features?.featured" class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-teal-500/15 blur-2xl"></div>
                <div class="relative flex items-center justify-between">
                    <h3 class="text-lg font-bold">{{ plan.name }}</h3>
                    <span v-if="plan.features?.featured" class="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-teal-500 to-cyan-600 px-2.5 py-0.5 text-xs font-semibold text-white">
                        <Sparkles class="size-3" /> Popular
                    </span>
                </div>
                <div class="relative mt-3 flex items-end gap-1">
                    <span
                        class="text-4xl font-extrabold tracking-tight"
                        :class="discountFor(plan) > 0 ? 'text-teal-600 dark:text-teal-400' : ''"
                    >{{ discountFor(plan) > 0 ? money(finalPrice(plan)) : '₹' + plan.price }}</span>
                    <span class="pb-1 text-sm text-muted-foreground">/{{ plan.interval }}</span>
                </div>
                <div v-if="discountFor(plan) > 0" class="relative mt-1 flex items-center gap-2 text-sm">
                    <span class="text-muted-foreground line-through">₹{{ plan.price }}</span>
                    <span class="inline-flex items-center rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-500 ring-1 ring-inset ring-rose-500/20">
                        Save {{ money(discountFor(plan)) }}
                    </span>
                </div>
                <ul class="relative mt-6 flex-1 space-y-3 text-sm">
                    <li class="flex items-center gap-2">
                        <span class="flex size-5 items-center justify-center rounded-full bg-teal-500/15 text-teal-600 dark:text-teal-300"><Check class="size-3.5" /></span>
                        {{ plan.features?.job_post_limit ?? 0 }} job posts
                    </li>
                    <li class="flex items-center gap-2">
                        <span class="flex size-5 items-center justify-center rounded-full bg-teal-500/15 text-teal-600 dark:text-teal-300"><Check class="size-3.5" /></span>
                        {{ plan.features?.contact_unlock_limit ?? 0 }} contact unlocks
                    </li>
                    <li v-if="plan.features?.featured" class="flex items-center gap-2">
                        <span class="flex size-5 items-center justify-center rounded-full bg-teal-500/15 text-teal-600 dark:text-teal-300"><Check class="size-3.5" /></span>
                        Featured listings
                    </li>
                </ul>
                <button
                    class="relative mt-6 rounded-xl px-4 py-2.5 text-sm font-semibold transition active:scale-95 disabled:opacity-50"
                    :class="current?.plan.id === plan.id
                        ? 'cursor-default border text-muted-foreground'
                        : 'bg-gradient-to-r from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-600/25 hover:opacity-90'"
                    :disabled="current?.plan.id === plan.id"
                    @click="subscribe(plan)"
                >
                    {{ current?.plan.id === plan.id ? 'Current plan' : 'Subscribe' }}
                </button>
            </div>
        </div>
    </div>
</template>
