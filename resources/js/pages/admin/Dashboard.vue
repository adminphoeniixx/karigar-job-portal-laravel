<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    ArrowUpRight,
    Ban,
    Briefcase,
    FileText,
    Gauge,
    IndianRupee,
    ShieldCheck,
    TrendingUp,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
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

interface Deltas {
    workers: number;
    employers: number;
    activeJobs: number;
    applications: number;
}

interface Breakdown {
    pending: number;
    accepted: number;
    rejected: number;
    withdrawn: number;
}

const props = defineProps<{
    stats: Stats;
    deltas: Deltas;
    applicationBreakdown: Breakdown;
    signups: { date: string; count: number }[];
    recentUsers: { id: number; name: string; email: string; role: string; created_at: string; suspended_at: string | null }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Admin Overview', href: '/admin/overview' }] } });

const inr = (n: number) => '₹' + new Intl.NumberFormat('en-IN').format(Math.round(n));

// --- Primary KPI tiles, each with a real "new this week" delta ---
const tiles = computed(() => [
    { label: 'Workers', value: props.stats.workers, delta: props.deltas.workers, icon: Users, tone: 'orange' },
    { label: 'Employers', value: props.stats.employers, delta: props.deltas.employers, icon: Briefcase, tone: 'blue' },
    { label: 'Active jobs', value: props.stats.activeJobs, delta: props.deltas.activeJobs, sub: `${props.stats.totalJobs} total`, icon: Briefcase, tone: 'green' },
    { label: 'Applications', value: props.stats.applications, delta: props.deltas.applications, icon: FileText, tone: 'purple' },
]);

const toneClass: Record<string, string> = {
    orange: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    green: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
    blue: 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
    purple: 'bg-purple-500/10 text-purple-600 dark:text-purple-300',
};

// --- Signups area chart (14 days) with interactive hover ---
const CW = 720;
const CH = 170;
const PAD = 10;
const signupCounts = computed(() => props.signups.map((s) => s.count));
const signupPts = computed(() => chartPoints(signupCounts.value, CW, CH, PAD));
const signupLine = computed(() => linePath(signupPts.value));
const signupArea = computed(() => areaPath(signupLine.value, CW, CH, PAD));
const totalSignups = computed(() => signupCounts.value.reduce((a, b) => a + b, 0));
const peakSignups = computed(() => Math.max(1, ...signupCounts.value));

const chartWrap = ref<HTMLElement | null>(null);
const hoverIndex = ref<number | null>(null);

const onChartMove = (e: MouseEvent) => {
    const el = chartWrap.value;
    if (!el || props.signups.length < 2) return;
    const rect = el.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    hoverIndex.value = Math.round(ratio * (props.signups.length - 1));
};
const onChartLeave = () => (hoverIndex.value = null);

// Percentage positions of the active point within the plotting box.
const hoverLeftPct = computed(() =>
    hoverIndex.value === null ? 0 : (signupPts.value[hoverIndex.value].x / CW) * 100,
);
const hoverTopPct = computed(() =>
    hoverIndex.value === null ? 0 : (signupPts.value[hoverIndex.value].y / CH) * 100,
);
const formatDay = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });

// --- Application funnel composition ---
const funnelSegments = computed(() => {
    const b = props.applicationBreakdown;
    const total = b.pending + b.accepted + b.rejected + b.withdrawn || 1;
    return [
        { key: 'accepted', label: 'Accepted', value: b.accepted, color: '#2bc155', pct: (b.accepted / total) * 100 },
        { key: 'pending', label: 'Pending', value: b.pending, color: '#f5a623', pct: (b.pending / total) * 100 },
        { key: 'rejected', label: 'Rejected', value: b.rejected, color: '#f43f7a', pct: (b.rejected / total) * 100 },
        { key: 'withdrawn', label: 'Withdrawn', value: b.withdrawn, color: '#94a3b8', pct: (b.withdrawn / total) * 100 },
    ];
});
const funnelTotal = computed(() => funnelSegments.value.reduce((a, s) => a + s.value, 0));

// --- Things needing admin attention ---
const attention = computed(() => [
    { label: 'Pending KYC reviews', value: props.stats.pendingKyc, href: '/admin/kyc', icon: ShieldCheck, tone: 'amber' },
    { label: 'Suspended accounts', value: props.stats.suspended, href: '/admin/users', icon: Ban, tone: 'rose' },
]);
const attentionTone: Record<string, string> = {
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
};

// --- Recent users ---
const roleBadge: Record<string, string> = {
    worker: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    employer: 'bg-blue-500/10 text-blue-600 dark:text-blue-300',
    admin: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
};
const avatarTone: Record<string, string> = {
    worker: 'bg-gradient-to-br from-orange-500 to-rose-600',
    employer: 'bg-gradient-to-br from-blue-500 to-indigo-600',
    admin: 'bg-gradient-to-br from-amber-500 to-orange-600',
};
const initials = (name: string) =>
    name
        .split(' ')
        .map((p) => p[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
const timeAgo = (iso: string) => {
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 3600) return `${Math.max(1, Math.floor(diff / 60))}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
};
</script>

<template>
    <Head title="Admin Overview" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-5 p-4 md:p-6">
        <PageHeader :icon="Gauge" title="Platform Overview" description="Live health of the Karigar marketplace" />

        <!-- Primary KPI tiles -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                v-for="t in tiles"
                :key="t.label"
                class="group rounded-2xl border bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-muted-foreground">{{ t.label }}</span>
                    <span class="flex size-9 items-center justify-center rounded-xl" :class="toneClass[t.tone]">
                        <component :is="t.icon" class="size-4.5" />
                    </span>
                </div>
                <div class="mt-3.5 text-3xl font-bold tabular-nums tracking-tight">
                    {{ new Intl.NumberFormat('en-IN').format(t.value) }}
                </div>
                <div class="mt-1.5 flex items-center gap-2">
                    <span
                        v-if="t.delta > 0"
                        class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-xs font-semibold text-emerald-600 dark:text-emerald-300"
                    >
                        <ArrowUpRight class="size-3" />{{ t.delta }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ t.delta > 0 ? 'this week' : (t.sub ?? 'no change this week') }}
                    </span>
                    <span v-if="t.delta > 0 && t.sub" class="text-xs text-muted-foreground">· {{ t.sub }}</span>
                </div>
            </div>
        </div>

        <!-- Signups chart + revenue -->
        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Signups area chart -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm lg:col-span-2">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-sm font-semibold">New signups</h2>
                        <p class="text-xs text-muted-foreground">Last 14 days</p>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold tabular-nums">{{ totalSignups }}</div>
                        <div class="text-xs text-muted-foreground">total joined</div>
                    </div>
                </div>

                <div
                    ref="chartWrap"
                    class="relative mt-5 select-none"
                    @mousemove="onChartMove"
                    @mouseleave="onChartLeave"
                >
                    <svg class="w-full" :viewBox="`0 0 ${CW} ${CH}`" fill="none" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="signup-area" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#f24711" stop-opacity="0.24" />
                                <stop offset="100%" stop-color="#f24711" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <line
                            v-for="g in 3"
                            :key="g"
                            x1="0"
                            :x2="CW"
                            :y1="(CH / 4) * g"
                            :y2="(CH / 4) * g"
                            stroke="currentColor"
                            class="text-border"
                            stroke-dasharray="3 7"
                        />
                        <path :d="signupArea" fill="url(#signup-area)" />
                        <path :d="signupLine" stroke="#f24711" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>

                    <!-- Hover crosshair + active point -->
                    <template v-if="hoverIndex !== null">
                        <div
                            class="pointer-events-none absolute top-0 bottom-6 w-px bg-orange-500/40"
                            :style="{ left: `${hoverLeftPct}%` }"
                        />
                        <div
                            class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-orange-500 bg-white shadow"
                            :style="{ left: `${hoverLeftPct}%`, top: `calc(${hoverTopPct}% * 0.92)` }"
                        />
                        <div
                            class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-lg border bg-popover px-2.5 py-1.5 text-center shadow-lg"
                            :style="{ left: `${Math.min(88, Math.max(12, hoverLeftPct))}%`, top: `calc(${hoverTopPct}% * 0.92 - 10px)` }"
                        >
                            <div class="text-sm font-bold tabular-nums leading-none">{{ signups[hoverIndex].count }}</div>
                            <div class="mt-0.5 text-[10px] text-muted-foreground">{{ formatDay(signups[hoverIndex].date) }}</div>
                        </div>
                    </template>

                    <!-- X axis: first / mid / last -->
                    <div class="mt-2 flex justify-between text-[10px] tabular-nums text-muted-foreground">
                        <span>{{ formatDay(signups[0].date) }}</span>
                        <span>{{ formatDay(signups[Math.floor(signups.length / 2)].date) }}</span>
                        <span>{{ formatDay(signups[signups.length - 1].date) }}</span>
                    </div>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">Peak day: {{ peakSignups }} new users</p>
            </div>

            <!-- Revenue hero -->
            <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 p-6 text-white shadow-lg shadow-orange-500/25">
                <div class="absolute -right-8 -top-8 size-32 rounded-full bg-white/10" />
                <div class="absolute -bottom-10 -left-6 size-28 rounded-full bg-white/10" />
                <div class="relative">
                    <div class="flex items-center gap-2 text-sm font-medium text-white/90">
                        <IndianRupee class="size-4" /> Monthly revenue
                    </div>
                    <div class="mt-3 text-4xl font-bold tracking-tight tabular-nums">{{ inr(stats.mrr) }}</div>
                    <div class="mt-1 inline-flex items-center gap-1 text-xs text-white/80">
                        <TrendingUp class="size-3.5" /> Recurring · MRR
                    </div>

                    <div class="mt-6 flex items-center justify-between border-t border-white/20 pt-4">
                        <div>
                            <div class="text-2xl font-bold tabular-nums">{{ stats.activeSubscriptions }}</div>
                            <div class="text-xs text-white/80">Active subscriptions</div>
                        </div>
                        <Link
                            href="/admin/plans"
                            class="inline-flex items-center gap-1 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold backdrop-blur transition hover:bg-white/25"
                        >
                            Plans <ArrowRight class="size-3.5" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Application funnel + needs attention -->
        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Application funnel -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm lg:col-span-2">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold">Applications funnel</h2>
                    <span class="text-xs text-muted-foreground">{{ funnelTotal }} total</span>
                </div>

                <div v-if="funnelTotal > 0" class="mt-4 flex h-3 gap-0.5 overflow-hidden rounded-full">
                    <div
                        v-for="s in funnelSegments"
                        :key="s.key"
                        :style="{ width: `${s.pct}%`, background: s.color }"
                        class="first:rounded-l-full last:rounded-r-full"
                        :title="`${s.label}: ${s.value}`"
                    />
                </div>
                <div v-else class="mt-4 rounded-lg bg-muted py-6 text-center text-xs text-muted-foreground">
                    No applications yet
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="s in funnelSegments" :key="s.key" class="flex items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ background: s.color }" />
                        <div class="min-w-0">
                            <div class="text-lg font-bold tabular-nums leading-none">{{ s.value }}</div>
                            <div class="truncate text-xs text-muted-foreground">{{ s.label }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Needs attention -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="text-sm font-semibold">Needs attention</h2>
                <div class="mt-4 flex flex-col gap-3">
                    <Link
                        v-for="a in attention"
                        :key="a.label"
                        :href="a.href"
                        class="flex items-center justify-between gap-3 rounded-xl border p-3 transition hover:bg-muted/50"
                    >
                        <div class="flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-xl" :class="attentionTone[a.tone]">
                                <component :is="a.icon" class="size-4.5" />
                            </span>
                            <span class="text-sm font-medium">{{ a.label }}</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="text-lg font-bold tabular-nums">{{ a.value }}</span>
                            <ArrowRight class="size-4 text-muted-foreground" />
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Recent signups -->
        <div class="rounded-2xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-6 py-4">
                <h2 class="text-sm font-semibold">Recent signups</h2>
                <Link href="/admin/users" class="inline-flex items-center gap-1 text-xs font-medium text-orange-600 hover:underline dark:text-orange-400">
                    View all users <ArrowRight class="size-3.5" />
                </Link>
            </div>
            <div class="divide-y">
                <div v-for="u in recentUsers" :key="u.id" class="flex items-center justify-between gap-3 px-6 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white"
                            :class="avatarTone[u.role] ?? 'bg-muted-foreground'"
                        >
                            {{ initials(u.name) }}
                        </span>
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ u.name }}</div>
                            <div class="truncate text-xs text-muted-foreground">{{ u.email }}</div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <span class="hidden text-xs text-muted-foreground sm:inline">{{ timeAgo(u.created_at) }}</span>
                        <span v-if="u.suspended_at" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">
                            Suspended
                        </span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="roleBadge[u.role]">{{ u.role }}</span>
                    </div>
                </div>
                <div v-if="recentUsers.length === 0" class="px-6 py-12 text-center text-sm text-muted-foreground">
                    No signups yet.
                </div>
            </div>
        </div>
    </div>
</template>
