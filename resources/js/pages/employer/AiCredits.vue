<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowDownLeft, ArrowUpRight, Coins, FileText, Info, Loader2, PhoneCall, Sparkles, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';

interface Summary {
    enabled: boolean;
    balance: number;
    held: number;
    available: number;
    per_minute: number;
    minutes: number;
    min_for_job: number;
    low: boolean;
    max_call_minutes: number;
}

interface Pack {
    id: number;
    name: string;
    price: number;
    gst_amount: number;
    price_with_gst: number;
    credits: number;
    bonus_credits: number;
    total_credits: number;
    minutes: number;
    popular: boolean;
}

interface Entry {
    id: number;
    type: string;
    type_label: string;
    direction: 'credit' | 'debit';
    credits: number;
    balance_after: number;
    description: string;
    amount_paid: number | null;
    invoice: { id: number; number: string; web_url: string; web_pdf_url: string } | null;
    created_label: string | null;
}

type Filter = 'all' | 'added' | 'spent';

const props = defineProps<{
    summary: Summary;
    packs: Pack[];
    rules: string[];
    ledger: { data: Entry[]; links: { url: string | null; label: string; active: boolean }[] };
    filter: Filter;
    totals: { added: number; spent: number; calls: number; recharged_amount: number };
    canBuy: boolean;
    paymentsConfigured: boolean;
    needsEmail: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AI Credits', href: '/employer/ai-credits' }] } });

const page = usePage();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const inr = (v: number) => '₹' + v.toLocaleString('en-IN', { maximumFractionDigits: 2 });
const num = (v: number) => v.toLocaleString('en-IN');

const tabs: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'added', label: 'Added' },
    { key: 'spent', label: 'Spent' },
];

const show = (filter: Filter) => router.get('/employer/ai-credits', { filter }, { preserveScroll: true, preserveState: true, replace: true });

const go = (url: string | null) => {
    if (url) router.get(url, {}, { preserveScroll: true, preserveState: true });
};

// Where the GST invoice goes; asked once when the account has no real email.
const email = ref('');
const buying = ref<number | null>(null);

const buy = (pack: Pack) => {
    buying.value = pack.id;
    router.post(
        `/employer/ai-credits/packs/${pack.id}/checkout`,
        props.needsEmail ? { email: email.value } : {},
        { preserveScroll: true, onFinish: () => (buying.value = null) },
    );
};

const typeTone: Record<string, string> = {
    recharge: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    bonus: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    welcome: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    call: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    admin_credit: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    admin_debit: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    refund: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
};
</script>

<template>
    <Head title="AI Credits" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Coins" title="AI Call Credits" description="AI calls ring your shortlisted applicants for you. Each call takes credits for the minutes it runs." />

        <div v-if="!summary.enabled" class="flex items-start gap-3 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 text-sm text-emerald-800 dark:text-emerald-200">
            <Sparkles class="mt-0.5 size-4 shrink-0" />
            AI calls are free right now — no credits are taken.
        </div>

        <!-- Balance -->
        <section class="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <p class="text-sm font-medium text-muted-foreground">Your balance</p>
                <div class="mt-2 flex flex-wrap items-end gap-x-3 gap-y-1">
                    <span class="text-5xl font-semibold tabular-nums tracking-tight">{{ num(summary.balance) }}</span>
                    <span class="pb-1.5 text-sm text-muted-foreground">credits · about {{ num(summary.minutes) }} min of calling</span>
                </div>
                <p v-if="summary.held > 0" class="mt-2 text-xs text-muted-foreground">
                    {{ summary.held }} held by calls on the line right now; the rest comes back when they end.
                </p>
                <div
                    v-if="summary.enabled && summary.low"
                    class="mt-4 flex items-start gap-2 rounded-xl bg-amber-500/10 px-3 py-2.5 text-sm text-amber-800 dark:text-amber-200"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    <span v-if="summary.available < summary.max_call_minutes * summary.per_minute">Not enough credits for an AI call. Shortlisted applicants are not being called until you recharge.</span>
                    <span v-else>Credits are running low. Recharge so screening calls keep going.</span>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3 lg:grid-cols-1">
                <div class="rounded-2xl border bg-card p-4 shadow-sm">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><ArrowDownLeft class="size-3.5 text-emerald-600" /> Added</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(totals.added) }}</p>
                </div>
                <div class="rounded-2xl border bg-card p-4 shadow-sm">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><ArrowUpRight class="size-3.5 text-orange-600" /> Spent</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(totals.spent) }}</p>
                </div>
                <div class="rounded-2xl border bg-card p-4 shadow-sm">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><PhoneCall class="size-3.5" /> Calls charged</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(totals.calls) }}</p>
                </div>
            </div>
        </section>

        <!-- Packs -->
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 class="text-lg font-semibold">Recharge</h2>
                <p v-if="!canBuy" class="text-xs text-muted-foreground">Only the account owner can recharge.</p>
                <p v-else-if="!paymentsConfigured" class="text-xs text-muted-foreground">Payments are not switched on yet.</p>
            </div>

            <div v-if="canBuy && needsEmail" class="max-w-md">
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Email for your GST invoice</label>
                <input
                    v-model="email"
                    type="email"
                    placeholder="you@company.com"
                    class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"
                />
                <InputError :message="errors.email" />
            </div>

            <div v-if="packs.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <article
                    v-for="pack in packs"
                    :key="pack.id"
                    class="relative flex flex-col rounded-2xl border bg-card p-5 shadow-sm"
                    :class="pack.popular ? 'border-orange-500/60 ring-1 ring-orange-500/30' : ''"
                >
                    <span v-if="pack.popular" class="absolute -top-2.5 right-4 rounded-full bg-primary px-2.5 py-0.5 text-[11px] font-semibold text-white">Popular</span>
                    <h3 class="font-semibold">{{ pack.name }}</h3>
                    <p class="mt-3 text-3xl font-semibold tabular-nums">
                        {{ num(pack.total_credits) }} <span class="text-sm font-medium text-muted-foreground">credits</span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="pack.bonus_credits > 0">{{ num(pack.credits) }} + <span class="font-semibold text-violet-600 dark:text-violet-300">{{ num(pack.bonus_credits) }} bonus</span> · </template>
                        about {{ num(pack.minutes) }} min
                    </p>
                    <div class="mt-4 border-t pt-4">
                        <p class="text-lg font-semibold">{{ inr(pack.price) }}</p>
                        <p v-if="pack.gst_amount > 0" class="text-xs text-muted-foreground">+ {{ inr(pack.gst_amount) }} GST = {{ inr(pack.price_with_gst) }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="!canBuy || !paymentsConfigured || buying !== null"
                        class="mt-4 inline-flex items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                        @click="buy(pack)"
                    >
                        <Loader2 v-if="buying === pack.id" class="size-4 animate-spin" />
                        Buy for {{ inr(pack.price_with_gst) }}
                    </button>
                </article>
            </div>
            <p v-else class="rounded-2xl border bg-card px-5 py-8 text-center text-sm text-muted-foreground">No credit packs are on sale right now.</p>

            <ul class="grid gap-1.5 rounded-2xl bg-muted/50 p-4 text-sm text-muted-foreground sm:grid-cols-2">
                <li v-for="rule in rules" :key="rule" class="flex items-start gap-2"><Info class="mt-0.5 size-3.5 shrink-0" /> {{ rule }}</li>
            </ul>
        </section>

        <!-- Ledger -->
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Credit history</h2>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="rounded-full border px-4 py-1.5 font-medium transition"
                        :class="filter === tab.key ? 'border-primary bg-primary text-white' : 'hover:bg-muted'"
                        @click="show(tab.key)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <div v-if="ledger.data.length" class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div
                    v-for="row in ledger.data"
                    :key="row.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-3.5 last:border-b-0 sm:flex-nowrap"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="typeTone[row.type] ?? 'bg-muted text-muted-foreground'">{{ row.type_label }}</span>
                            <span class="text-xs text-muted-foreground">{{ row.created_label }}</span>
                        </div>
                        <p class="mt-1 truncate text-sm">{{ row.description }}</p>
                        <div v-if="row.invoice || row.amount_paid" class="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span v-if="row.amount_paid">Paid {{ inr(row.amount_paid) }}</span>
                            <a v-if="row.invoice" :href="row.invoice.web_url" class="inline-flex items-center gap-1 font-medium text-orange-700 hover:underline dark:text-orange-300">
                                <FileText class="size-3" /> {{ row.invoice.number }}
                            </a>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-base font-semibold tabular-nums" :class="row.direction === 'credit' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                            {{ row.credits > 0 ? '+' : '−' }}{{ num(Math.abs(row.credits)) }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">Balance {{ num(row.balance_after) }}</p>
                    </div>
                </div>
            </div>
            <div v-else class="rounded-2xl border bg-card px-5 py-12 text-center shadow-sm">
                <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Coins class="size-6" /></div>
                <p class="mt-3 font-medium">Nothing here yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Recharges and AI calls show up here, each with the balance after it.</p>
            </div>

            <div v-if="ledger.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
                <button
                    v-for="link in ledger.links"
                    :key="link.label"
                    :disabled="!link.url"
                    class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                    :class="link.active ? 'border-orange-500 bg-orange-500/10 font-semibold text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                    @click="go(link.url)"
                    v-html="link.label"
                />
            </div>
        </section>
    </div>
</template>
