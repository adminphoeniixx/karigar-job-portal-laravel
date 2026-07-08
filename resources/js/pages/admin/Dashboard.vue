<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Gauge, Users, Briefcase, FileText, CreditCard, ShieldCheck, IndianRupee, Ban } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Stats {
    workers: number;
    employers: number;
    suspended: number;
    activeJobs: number;
    totalJobs: number;
    applications: number;
    activeSubscriptions: number;
    pendingKyc: number;
    mrr: number;
}

const props = defineProps<{
    stats: Stats;
    signups: { date: string; count: number }[];
    recentUsers: { id: number; name: string; email: string; role: string; created_at: string; suspended_at: string | null }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Admin Overview', href: '/admin/overview' }] } });

const inr = (n: number) => '₹' + new Intl.NumberFormat('en-IN').format(Math.round(n));

const tiles = computed(() => [
    { label: 'Workers', value: props.stats.workers.toString(), icon: Users, tone: 'teal' },
    { label: 'Employers', value: props.stats.employers.toString(), icon: Briefcase, tone: 'cyan' },
    { label: 'Active jobs', value: props.stats.activeJobs.toString(), sub: `${props.stats.totalJobs} total`, icon: Briefcase, tone: 'teal' },
    { label: 'Applications', value: props.stats.applications.toString(), icon: FileText, tone: 'slate' },
    { label: 'Active subscriptions', value: props.stats.activeSubscriptions.toString(), icon: CreditCard, tone: 'cyan' },
    { label: 'Monthly revenue', value: inr(props.stats.mrr), icon: IndianRupee, tone: 'teal' },
    { label: 'Pending KYC', value: props.stats.pendingKyc.toString(), icon: ShieldCheck, tone: 'amber' },
    { label: 'Suspended', value: props.stats.suspended.toString(), icon: Ban, tone: 'rose' },
]);

const toneClass: Record<string, string> = {
    teal: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    cyan: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
    slate: 'bg-muted text-muted-foreground',
};

const maxSignup = computed(() => Math.max(1, ...props.signups.map((s) => s.count)));

const roleBadge: Record<string, string> = {
    worker: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    employer: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    admin: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
};
</script>

<template>
    <Head title="Admin Overview" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Gauge" title="Platform Overview" description="Live health of the Karigar marketplace" />

        <!-- Stat tiles -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="t in tiles" :key="t.label" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-muted-foreground">{{ t.label }}</span>
                    <span class="flex size-8 items-center justify-center rounded-lg" :class="toneClass[t.tone]"><component :is="t.icon" class="size-4" /></span>
                </div>
                <div class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ t.value }}</div>
                <div v-if="t.sub" class="text-xs text-muted-foreground">{{ t.sub }}</div>
            </div>
        </div>

        <!-- Signups chart -->
        <div class="rounded-2xl border bg-card p-6 shadow-sm">
            <h2 class="text-sm font-semibold">New signups <span class="font-normal text-muted-foreground">· last 14 days</span></h2>
            <div class="mt-6 flex h-32 items-end gap-1.5">
                <div v-for="s in signups" :key="s.date" class="group flex flex-1 flex-col items-center justify-end gap-1.5">
                    <span class="text-[10px] font-medium tabular-nums text-muted-foreground opacity-0 transition group-hover:opacity-100">{{ s.count }}</span>
                    <div
                        class="w-full rounded-t bg-gradient-to-t from-teal-500/70 to-cyan-500/70 transition hover:from-teal-500 hover:to-cyan-500"
                        :style="{ height: Math.max(4, (s.count / maxSignup) * 100) + '%' }"
                    ></div>
                    <span class="text-[10px] tabular-nums text-muted-foreground">{{ s.date.slice(8, 10) }}</span>
                </div>
            </div>
        </div>

        <!-- Recent users -->
        <div class="rounded-2xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-6 py-4">
                <h2 class="text-sm font-semibold">Recent signups</h2>
                <Link href="/admin/users" class="text-xs font-medium text-teal-600 hover:underline dark:text-teal-400">View all users →</Link>
            </div>
            <div class="divide-y">
                <div v-for="u in recentUsers" :key="u.id" class="flex items-center justify-between gap-3 px-6 py-3">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ u.name }}</div>
                        <div class="truncate text-xs text-muted-foreground">{{ u.email }}</div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span v-if="u.suspended_at" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">Suspended</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="roleBadge[u.role]">{{ u.role }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
