<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck, Send, Undo2, AlertTriangle } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Row {
    id: number;
    job: string | null;
    employer: string | null;
    worker: string | null;
    amount: string;
    commission: string;
    payout_amount: string;
    status: string;
    status_label: string;
    created_at: string;
}

const props = defineProps<{
    escrows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { status?: string };
    payoutsConfigured: boolean;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Escrows', href: '/admin/escrows' }] } });

const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout>;
watch(status, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/escrows', { status: status.value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }, 200);
});

const inr = (n: string) => '₹' + new Intl.NumberFormat('en-IN').format(Math.round(Number(n)));

const release = (e: Row) => {
    if (window.confirm(`Release ${inr(e.payout_amount)} to ${e.worker}? This sends a real payout.`)) {
        router.post(`/admin/escrows/${e.id}/release`, {}, { preserveScroll: true });
    }
};
const refund = (e: Row) => {
    const note = window.prompt(`Refund ${inr(e.amount)} to ${e.employer}? Optional note:`, '');
    if (note !== null) {
        router.post(`/admin/escrows/${e.id}/refund`, { note }, { preserveScroll: true });
    }
};

const statusBadge: Record<string, string> = {
    pending: 'bg-muted text-muted-foreground',
    funded: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    release_requested: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    released: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    refunded: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    disputed: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

const canRelease = (s: string) => s === 'funded' || s === 'release_requested' || s === 'disputed';
</script>

<template>
    <Head title="Escrows" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="ShieldCheck" title="Escrow Payments" description="Funds held between employers and workers" />

        <div v-if="!payoutsConfigured" class="flex items-start gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-semibold">RazorpayX payouts not configured</p>
                <p class="mt-0.5">Set <code>RAZORPAYX_ACCOUNT_NUMBER</code> and Razorpay keys before releasing funds. Releasing now will fail until configured.</p>
            </div>
        </div>

        <!-- Filter -->
        <div class="rounded-2xl border bg-card p-4 shadow-sm">
            <select v-model="status" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40">
                <option value="">All statuses</option>
                <option value="pending">Awaiting payment</option>
                <option value="funded">Funds held</option>
                <option value="release_requested">Release requested</option>
                <option value="released">Paid to worker</option>
                <option value="refunded">Refunded</option>
                <option value="disputed">Disputed</option>
            </select>
        </div>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-for="e in escrows.data" :key="e.id" class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4 last:border-0">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium">{{ e.job ?? 'Job' }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadge[e.status]">{{ e.status_label }}</span>
                    </div>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ e.employer }} → {{ e.worker }} · {{ e.created_at }}
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right text-sm">
                        <div class="font-semibold tabular-nums">{{ inr(e.amount) }}</div>
                        <div class="text-xs text-muted-foreground tabular-nums">payout {{ inr(e.payout_amount) }} · fee {{ inr(e.commission) }}</div>
                    </div>
                    <div v-if="canRelease(e.status)" class="flex items-center gap-1">
                        <button
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-teal-600 transition hover:bg-teal-500/10 dark:text-teal-400"
                            @click="release(e)"
                        >
                            <Send class="size-3.5" /> Release
                        </button>
                        <button
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                            @click="refund(e)"
                        >
                            <Undo2 class="size-3.5" /> Refund
                        </button>
                    </div>
                </div>
            </div>
            <div v-if="escrows.data.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">No escrows yet.</div>
        </div>

        <!-- Pagination -->
        <div v-if="escrows.links.length > 3" class="flex flex-wrap justify-center gap-1">
            <Link
                v-for="(l, i) in escrows.links"
                :key="i"
                :href="l.url ?? ''"
                preserve-scroll
                class="rounded-lg px-3 py-1.5 text-sm transition"
                :class="[l.active ? 'bg-teal-500 text-white' : l.url ? 'border hover:bg-muted' : 'cursor-default text-muted-foreground opacity-50']"
                v-html="l.label"
            />
        </div>
    </div>
</template>
