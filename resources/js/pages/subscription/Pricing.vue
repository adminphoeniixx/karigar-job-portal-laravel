<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, CreditCard, Download, Mail, Receipt, Sparkles, Tag, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    type: 'job' | 'database';
    price: string;
    interval: string;
    features: { job_post_limit?: number; contact_unlock_limit?: number; featured?: boolean } | null;
    razorpay_plan_id: string | null;
    feature_list: string[];
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

interface OrderPayment {
    invoice_id: number;
    invoice_number: string;
    cycle: number;
    renewal: boolean;
    amount: number;
    paid_label: string;
    period_label: string | null;
    web_url: string;
    web_pdf_url: string;
}

type PaymentStatus = 'paid' | 'pending' | 'not_completed' | 'renewal_failed';
type PlanStatus = 'active' | 'expired' | 'cancelled' | 'completed' | 'none';

interface Order {
    id: number;
    order_number: string;
    plan: string | null;
    plan_type: string | null;
    ordered_label: string | null;
    amount: number;
    discount: number;
    coupon: string | null;
    payment_status: PaymentStatus;
    plan_status: PlanStatus;
    period_label: string | null;
    total_paid: number;
    payments: OrderPayment[];
}

const props = defineProps<{
    plans: Plan[];
    current: { id: number; status: string; ends_at: string | null; plan: Plan } | null;
    currentDatabase: { id: number; status: string; ends_at: string | null; plan: Plan } | null;
    razorpayConfigured: boolean;
    couponResult: CouponResult | null;
    gstPercent: number;
    orders: Order[];
    billingEmail: string | null;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Subscription', href: '/subscription' }] } });

const paymentPill: Record<PaymentStatus, string> = {
    paid: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
    pending: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300',
    not_completed: 'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300',
    renewal_failed: 'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300',
};
const planPill: Record<PlanStatus, string> = {
    active: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
    expired: 'bg-muted text-muted-foreground ring-border',
    cancelled: 'bg-muted text-muted-foreground ring-border',
    completed: 'bg-muted text-muted-foreground ring-border',
    none: '',
};

// "Buy Database" on the dashboard lands here with #database.
onMounted(() => {
    if (window.location.hash === '#database') {
        document.getElementById('database')?.scrollIntoView({ behavior: 'smooth' });
    }
});

// Job plans and database plans are bought separately; one of each can run at once.
const sections = computed(() =>
    [
        { key: 'job', plans: props.plans.filter((p) => p.type !== 'database') },
        { key: 'database', plans: props.plans.filter((p) => p.type === 'database') },
    ].filter((section) => section.plans.length),
);

const isCurrent = (plan: Plan) => props.current?.plan.id === plan.id || props.currentDatabase?.plan.id === plan.id;
// "Active till 06 Nov 2026" under a plan that is already bought.
const activeTill = (plan: Plan): string | null => {
    const sub = props.current?.plan.id === plan.id ? props.current : props.currentDatabase?.plan.id === plan.id ? props.currentDatabase : null;

    return sub?.ends_at ? new Date(sub.ends_at).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Kolkata' }) : null;
};

// ── Plan details popup ──────────────────────────────────────────────
const selected = ref<Plan | null>(null);

const openPlan = (plan: Plan) => {
    selected.value = plan;
};

const closePlan = () => {
    selected.value = null;
};

// ── Coupon (applied inside the popup, revalidated by the server) ────
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

// GST is charged on the discounted base price.
const gstFor = (plan: Plan): number => Math.round(finalPrice(plan) * props.gstPercent) / 100;
const totalFor = (plan: Plan): number => Math.round((finalPrice(plan) + gstFor(plan)) * 100) / 100;

// Whole rupees as "₹999", paise always to two places: "₹99.90", never "₹99.9".
const money = (n: number) =>
    '₹' + n.toLocaleString('en-IN', Number.isInteger(n) ? {} : { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const selectedDiscount = computed(() => (selected.value ? discountFor(selected.value) : 0));

const subscribing = ref(false);

// The tax invoice is emailed; a phone-OTP account has no real address yet, so
// the popup asks for one before the payment.
const invoiceEmail = ref('');
const page = usePage();
const emailError = computed(() => (page.props.errors as Record<string, string | undefined>).email);

const subscribe = () => {
    const plan = selected.value;
    if (!plan) return;
    // Only send the coupon when it actually discounts this plan (server re-validates).
    const payload: Record<string, string> = discountFor(plan) > 0 && props.couponResult?.valid ? { coupon: props.couponResult.code } : {};
    if (!props.billingEmail) payload.email = invoiceEmail.value.trim();
    subscribing.value = true;
    router.post(`/subscription/${plan.id}/subscribe`, payload, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => (subscribing.value = false),
    });
};
</script>

<template>
    <Head title="Subscription" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="CreditCard" :title="$t('subscription.title')" :description="$t('subscription.subtitle')" />

        <div v-if="current || currentDatabase" class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border bg-orange-500/5 px-5 py-4">
            <div v-for="sub in [current, currentDatabase].filter((s) => s !== null)" :key="sub.id" class="flex items-center gap-2 text-sm">
                <span class="flex size-9 items-center justify-center rounded-full bg-orange-500/15 text-orange-600 dark:text-orange-300"><Check class="size-5" /></span>
                <span>
                    {{ sub.plan.type === 'database' ? $t('subscription.databasePlan') : $t('subscription.activePlan') }}: <strong>{{ sub.plan.name }}</strong>
                    <span class="ml-2 inline-flex items-center rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold capitalize text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300">{{ sub.status }}</span>
                </span>
            </div>
        </div>

        <div v-if="!razorpayConfigured" class="flex items-start gap-3 rounded-2xl border border-amber-400/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <span>Razorpay test keys are not configured yet. Plans are shown, but checkout will work once the keys are added to <code class="rounded bg-amber-500/20 px-1">.env</code>.</span>
        </div>

        <section v-for="section in sections" :id="section.key" :key="section.key" class="flex scroll-mt-6 flex-col gap-4">
            <div>
                <h2 class="text-lg font-bold">{{ section.key === 'database' ? $t('subscription.databasePlans') : $t('subscription.jobPlans') }}</h2>
                <p class="text-sm text-muted-foreground">{{ section.key === 'database' ? $t('subscription.databasePlansHint') : $t('subscription.jobPlansHint') }}</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="plan in section.plans"
                    :key="plan.id"
                    class="relative flex flex-col overflow-hidden rounded-3xl border bg-card p-6 shadow-sm transition hover:shadow-md"
                    :class="plan.features?.featured ? 'border-orange-500/40 ring-2 ring-orange-500/20' : ''"
                >
                    <div v-if="plan.features?.featured" class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-orange-500/15 blur-2xl"></div>
                    <!-- Hangs from the top edge so a long plan name keeps the full width -->
                    <span v-if="plan.features?.featured" class="absolute right-6 top-0 inline-flex items-center gap-1 rounded-b-lg bg-primary px-2.5 py-0.5 text-xs font-semibold text-white">
                        <Sparkles class="size-3" /> {{ $t('subscription.popular') }}
                    </span>
                    <div class="relative">
                        <h3 class="text-lg font-bold">{{ plan.name }}</h3>
                    </div>
                    <div class="relative mt-3 flex items-end gap-1">
                        <span
                            class="text-4xl font-bold tracking-tight"
                            :class="discountFor(plan) > 0 ? 'text-orange-600 dark:text-orange-400' : ''"
                        >{{ money(finalPrice(plan)) }}</span>
                        <span class="pb-1 text-sm text-muted-foreground">/{{ plan.interval }}</span>
                    </div>
                    <div v-if="gstPercent > 0" class="relative mt-0.5 text-[11px] text-muted-foreground">
                        + {{ gstPercent }}% GST · {{ money(totalFor(plan)) }} total
                    </div>
                    <div v-if="discountFor(plan) > 0" class="relative mt-1 flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground line-through">{{ money(parseFloat(plan.price)) }}</span>
                        <span class="inline-flex items-center rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-500 ring-1 ring-inset ring-rose-500/20">
                            {{ $t('subscription.saveAmount') }} {{ money(discountFor(plan)) }}
                        </span>
                    </div>
                    <ul class="relative mt-6 flex-1 space-y-3 text-sm">
                        <li v-for="line in plan.feature_list" :key="line" class="flex items-center gap-2">
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-orange-500/15 text-orange-600 dark:text-orange-300"><Check class="size-3.5" /></span>
                            {{ line }}
                        </li>
                    </ul>
                    <button
                        class="relative mt-6 rounded-xl px-4 py-2.5 text-sm font-semibold transition active:scale-95 disabled:opacity-50"
                        :class="isCurrent(plan)
                            ? 'cursor-default border text-muted-foreground'
                            : 'bg-primary text-white shadow-lg shadow-orange-600/25 hover:opacity-90'"
                        :disabled="isCurrent(plan)"
                        @click="openPlan(plan)"
                    >
                        <span v-if="isCurrent(plan)" class="inline-flex items-center gap-1.5"><Check class="size-4" /> {{ $t('subscription.alreadyPurchased') }}</span>
                        <template v-else>{{ $t('subscription.viewDetails') }}</template>
                    </button>
                    <p v-if="isCurrent(plan) && activeTill(plan)" class="mt-2 text-center text-xs text-muted-foreground">
                        {{ $t('subscription.activeTill') }} {{ activeTill(plan) }}
                    </p>
                </div>
            </div>
        </section>
        <!-- Order history: every checkout, paid or not, with its invoices. -->
        <div v-if="orders.length" id="orders" class="rounded-2xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4 sm:px-6">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><Receipt class="size-4 text-orange-500" /> {{ $t('subscription.orderHistory') }}</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">{{ $t('subscription.orderHistoryHint') }}</p>
            </div>
            <div class="divide-y">
                <div v-for="order in orders" :key="order.id" class="px-5 py-4 text-sm sm:px-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ order.plan }}</span>
                                <span class="text-xs text-muted-foreground">{{ order.plan_type === 'database' ? $t('subscription.databasePlan') : $t('subscription.jobPlan') }}</span>
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="paymentPill[order.payment_status]">
                                    {{ $t(`subscription.payment_${order.payment_status}`) }}
                                </span>
                                <span v-if="order.plan_status !== 'none'" class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="planPill[order.plan_status]">
                                    {{ $t(`subscription.plan_${order.plan_status}`) }}
                                </span>
                            </div>
                            <div class="mt-1 text-xs text-muted-foreground">
                                {{ order.order_number }} · {{ order.ordered_label }}
                                <template v-if="order.period_label"> · {{ order.period_label }}</template>
                                <template v-if="order.coupon"> · {{ $t('subscription.couponCode') }} {{ order.coupon }}</template>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold tabular-nums">{{ money(order.payment_status === 'paid' ? order.total_paid : order.amount) }}</div>
                            <div v-if="order.payments.length > 1" class="text-[11px] text-muted-foreground">{{ order.payments.length }} {{ $t('subscription.payments') }}</div>
                        </div>
                    </div>

                    <p v-if="order.payment_status === 'not_completed'" class="mt-2 text-xs text-muted-foreground">{{ $t('subscription.notCompletedHint') }}</p>
                    <p v-else-if="order.payment_status === 'pending'" class="mt-2 text-xs text-muted-foreground">{{ $t('subscription.pendingHint') }}</p>
                    <p v-else-if="order.payment_status === 'renewal_failed'" class="mt-2 text-xs text-rose-600 dark:text-rose-400">{{ $t('subscription.renewalFailedHint') }}</p>

                    <!-- Each payment on the order, with its tax invoice. -->
                    <div v-if="order.payments.length" class="mt-3 divide-y rounded-xl border bg-muted/20">
                        <div v-for="pay in order.payments" :key="pay.invoice_id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 sm:px-4">
                            <div class="min-w-0">
                                <div class="text-xs font-semibold">
                                    {{ pay.renewal ? $t('subscription.renewal') : $t('subscription.firstPayment') }} · {{ pay.paid_label }}
                                </div>
                                <div class="text-[11px] text-muted-foreground">
                                    {{ pay.invoice_number }}<template v-if="pay.period_label"> · {{ pay.period_label }}</template>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-semibold tabular-nums">{{ money(pay.amount) }}</span>
                                <Link :href="pay.web_url" class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">{{ $t('subscription.viewInvoice') }}</Link>
                                <a :href="pay.web_pdf_url" class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                    <Download class="size-3.5" /> PDF
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Plan details popup -->
    <div v-if="selected" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closePlan">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="flex items-center gap-2 text-lg font-bold">
                        {{ selected.name }}
                        <span v-if="selected.features?.featured" class="inline-flex items-center gap-1 rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-white">
                            <Sparkles class="size-2.5" /> {{ $t('subscription.popular') }}
                        </span>
                    </h3>
                    <p class="mt-0.5 text-sm capitalize text-muted-foreground">{{ $t('subscription.billed') }} {{ selected.interval }}</p>
                </div>
                <button class="rounded-lg p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground" @click="closePlan">
                    <X class="size-5" />
                </button>
            </div>

            <!-- Plan details -->
            <ul class="mt-5 space-y-2.5 rounded-xl bg-muted/40 p-4 text-sm">
                <li v-for="line in selected.feature_list" :key="line" class="flex items-center gap-2">
                    <span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-orange-500/15 text-orange-600 dark:text-orange-300"><Check class="size-3.5" /></span>
                    {{ line }}
                </li>
            </ul>

            <!-- Coupon -->
            <div class="mt-5">
                <label class="mb-1.5 flex items-center gap-1.5 text-sm font-semibold"><Tag class="size-4 text-orange-500" /> {{ $t('subscription.couponCode') }}</label>
                <div class="flex gap-2">
                    <input
                        v-model="code"
                        placeholder="e.g. WELCOME50"
                        class="h-10 flex-1 rounded-xl border bg-background px-3 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                        @keyup.enter="applyCoupon"
                    />
                    <button
                        type="button"
                        :disabled="applying || !code.trim()"
                        class="h-10 rounded-xl border px-4 text-sm font-semibold transition hover:bg-muted disabled:opacity-50"
                        @click="applyCoupon"
                    >
                        {{ applying ? $t('subscription.checking') : $t('subscription.applyCoupon') }}
                    </button>
                </div>
                <p v-if="couponResult && couponResult.valid && selectedDiscount > 0" class="mt-1.5 flex items-center gap-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                    <Check class="size-3.5" /> Coupon {{ couponResult.code }} applied — you save {{ money(selectedDiscount) }}.
                </p>
                <p v-else-if="couponResult && couponResult.valid && selectedDiscount === 0" class="mt-1.5 flex items-center gap-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                    <AlertTriangle class="size-3.5" /> Coupon {{ couponResult.code }} doesn't apply to this plan.
                    <button class="underline" @click="clearCoupon">Remove</button>
                </p>
                <p v-else-if="couponResult && !couponResult.valid" class="mt-1.5 flex items-center gap-1 text-xs font-medium text-rose-500">
                    <AlertTriangle class="size-3.5" /> {{ couponResult.message }}
                </p>
            </div>

            <!-- Price summary (with GST breakup) -->
            <div class="mt-5 space-y-1.5 rounded-xl border p-4 text-sm">
                <div class="flex justify-between text-muted-foreground">
                    <span>{{ $t('subscription.planPrice') }}</span><span>{{ money(parseFloat(selected.price)) }}</span>
                </div>
                <div v-if="selectedDiscount > 0" class="flex justify-between font-medium text-emerald-600 dark:text-emerald-400">
                    <span>{{ $t('subscription.couponDiscount') }}</span><span>− {{ money(selectedDiscount) }}</span>
                </div>
                <div class="flex justify-between text-muted-foreground">
                    <span>{{ $t('subscription.gst') }} ({{ gstPercent }}%)</span><span>+ {{ money(gstFor(selected)) }}</span>
                </div>
                <div class="flex justify-between border-t pt-2 text-base font-bold">
                    <span>{{ $t('subscription.totalPayable') }}</span>
                    <span>{{ money(totalFor(selected)) }} <span class="text-xs font-normal text-muted-foreground">/{{ selected.interval }}</span></span>
                </div>
            </div>

            <!-- Where the GST invoice goes -->
            <div class="mt-5">
                <template v-if="billingEmail">
                    <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Mail class="size-3.5 shrink-0 text-orange-500" /> {{ $t('subscription.invoiceEmailTo') }}
                        <span class="font-medium text-foreground">{{ billingEmail }}</span>
                    </p>
                </template>
                <template v-else>
                    <label for="invoice-email" class="mb-1.5 flex items-center gap-1.5 text-sm font-semibold">
                        <Mail class="size-4 text-orange-500" /> {{ $t('subscription.invoiceEmail') }}
                    </label>
                    <input
                        id="invoice-email"
                        v-model="invoiceEmail"
                        type="email"
                        required
                        autocomplete="email"
                        placeholder="you@company.com"
                        class="h-10 w-full rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                        @keyup.enter="subscribe"
                    />
                    <p v-if="emailError" class="mt-1.5 text-xs font-medium text-rose-500">{{ emailError }}</p>
                    <p v-else class="mt-1.5 text-xs text-muted-foreground">{{ $t('subscription.invoiceEmailHint') }}</p>
                </template>
            </div>

            <button
                :disabled="subscribing || (!billingEmail && !invoiceEmail.trim())"
                class="mt-5 w-full rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-[0.99] disabled:opacity-60"
                @click="subscribe"
            >
                {{ subscribing ? $t('subscription.startingCheckout') : `${$t('subscription.subscribe')} — ${money(totalFor(selected))} ${$t('subscription.inclGst')}` }}
            </button>
            <p class="mt-2 text-center text-[11px] text-muted-foreground">{{ $t('subscription.securePayment') }}</p>
        </div>
    </div>
</template>
