<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Gauge, Users, Briefcase, FileText, CreditCard, ShieldCheck, IndianRupee, Ban } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Sparkline from '@/components/Sparkline.vue';
import { areaPath, chartPoints, linePath } from '@/lib/chart';

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

// Decorative trend waves cycled across the tiles (Jobick style)
const waves = [
    [8, 14, 9, 16, 11, 18, 13, 20],
    [12, 9, 15, 11, 17, 12, 19, 16],
    [10, 16, 12, 9, 15, 12, 18, 14],
    [14, 10, 16, 12, 18, 13, 17, 20],
];

const toneColor: Record<string, string> = {
    orange: '#f24711',
    green: '#2bc155',
    blue: '#4a6cf7',
    purple: '#c544d8',
    amber: '#f5a623',
    rose: '#f43f7a',
};

const tiles = computed(() => [
    { label: 'Workers', value: props.stats.workers.toString(), icon: Users, tone: 'orange' },
    { label: 'Employers', value: props.stats.employers.toString(), icon: Briefcase, tone: 'blue' },
    { label: 'Active jobs', value: props.stats.activeJobs.toString(), sub: `${props.stats.totalJobs} total`, icon: Briefcase, tone: 'green' },
    { label: 'Applications', value: props.stats.applications.toString(), icon: FileText, tone: 'purple' },
    { label: 'Active subscriptions', value: props.stats.activeSubscriptions.toString(), icon: CreditCard, tone: 'blue' },
    { label: 'Monthly revenue', value: inr(props.stats.mrr), icon: IndianRupee, tone: 'orange' },
    { label: 'Pending KYC', value: props.stats.pendingKyc.toString(), icon: ShieldCheck, tone: 'amber' },
    { label: 'Suspended', value: props.stats.suspended.toString(), icon: Ban, tone: 'rose' },
]);

const toneClass: Record<string, string> = {
    orange: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    green: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
    blue: 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
    purple: 'bg-purple-500/10 text-purple-600 dark:text-purple-300',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
};

// Smooth area chart for signups (Jobick style)
const CW = 720;
const CH = 150;
const signupCounts = computed(() => props.signups.map((s) => s.count));
const signupPts = computed(() => chartPoints(signupCounts.value, CW, CH, 8));
const signupLine = computed(() => linePath(signupPts.value));
const signupArea = computed(() => areaPath(signupLine.value, CW, CH, 8));

const roleBadge: Record<string, string> = {
    worker: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    employer: 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
    admin: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
};

const roleDot: Record<string, string> = {
    worker: 'bg-orange-500',
    employer: 'bg-blue-500',
    admin: 'bg-amber-500',
};
</script>

<template>
    <Head title="Admin Overview" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Gauge" title="Platform Overview" description="Live health of the Karigar marketplace" />

        <!-- Stat tiles -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="(t, i) in tiles" :key="t.label" class="rounded-2xl border bg-card p-5 shadow-sm transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-muted-foreground">{{ t.label }}</span>
                    <span class="flex size-8 items-center justify-center rounded-lg" :class="toneClass[t.tone]"><component :is="t.icon" class="size-4" /></span>
                </div>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div>
                        <div class="text-2xl font-bold tabular-nums tracking-tight">{{ t.value }}</div>
                        <div v-if="t.sub" class="text-xs text-muted-foreground">{{ t.sub }}</div>
                    </div>
                    <Sparkline :points="waves[i % waves.length]" :color="toneColor[t.tone]" :width="72" :height="30" />
                </div>
            </div>
        </div>

        <!-- Signups chart -->
        <div class="rounded-2xl border bg-card p-6 shadow-sm">
            <h2 class="text-sm font-semibold">New signups <span class="font-normal text-muted-foreground">· last 14 days</span></h2>
            <svg class="mt-5 w-full" :viewBox="`0 0 ${CW} ${CH}`" fill="none" preserveAspectRatio="none" aria-hidden="true">
                <defs>
                    <linearGradient id="signup-area" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#f24711" stop-opacity="0.22" />
                        <stop offset="100%" stop-color="#f24711" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <line
                    v-for="g in 3"
                    :key="g"
                    x1="8"
                    :x2="CW - 8"
                    :y1="(CH / 4) * g"
                    :y2="(CH / 4) * g"
                    stroke="currentColor"
                    class="text-border"
                    stroke-dasharray="4 6"
                />
                <path :d="signupArea" fill="url(#signup-area)" />
                <path :d="signupLine" stroke="#f24711" stroke-width="3" stroke-linecap="round" />
                <circle v-for="(p, i) in signupPts" :key="i" :cx="p.x" :cy="p.y" r="3.5" fill="#fff" stroke="#f24711" stroke-width="2" />
            </svg>
            <div class="mt-2 flex justify-between px-1">
                <span v-for="s in signups" :key="s.date" class="text-[10px] tabular-nums text-muted-foreground">{{ s.date.slice(8, 10) }}</span>
            </div>
        </div>

        <!-- Recent users -->
        <div class="rounded-2xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-6 py-4">
                <h2 class="text-sm font-semibold">Recent signups</h2>
                <Link href="/admin/users" class="text-xs font-medium text-orange-600 hover:underline dark:text-orange-400">View all users →</Link>
            </div>
            <div class="divide-y">
                <div v-for="u in recentUsers" :key="u.id" class="flex items-center justify-between gap-3 px-6 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="size-2.5 shrink-0 rounded-full" :class="roleDot[u.role] ?? 'bg-muted-foreground'"></span>
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ u.name }}</div>
                            <div class="truncate text-xs text-muted-foreground">{{ u.email }}</div>
                        </div>
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
