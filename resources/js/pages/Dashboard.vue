<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowUpRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { dashboard } from '@/routes';

interface Stat {
    label: string;
    value: string;
    hint: string;
    tone: 'emerald' | 'amber' | 'violet';
}

interface Table {
    title: string;
    columns: string[];
    rows: (string | number)[][];
}

interface RangeData {
    labels: string[];
    series: number[][];
}

const props = defineProps<{
    greeting: string;
    role: string;
    stats: Stat[];
    table: Table;
    activity: Record<string, RangeData>;
}>();

const { t } = useI18n();

// Backend sends English labels; translate the known ones client-side.
const backendKeys: Record<string, string> = {
    'KYC Status': 'dashboard.kycStatus',
    'Not submitted': 'dashboard.notSubmitted',
    'Verification': 'dashboard.verification',
    'Available Jobs': 'dashboard.availableJobs',
    'Near you': 'dashboard.nearYou',
    'Profile': 'dashboard.profileLabel',
    'Active': 'status.active',
    'Incomplete': 'dashboard.incomplete',
    'Skills': 'dashboard.skillsHint',
    'Latest jobs': 'dashboard.latestJobs',
    'My Jobs': 'nav.myJobs',
    'Total posted': 'dashboard.totalPosted',
    'Active Jobs': 'dashboard.activeJobs',
    'Live now': 'dashboard.liveNow',
    'Subscription': 'nav.subscription',
    'None': 'dashboard.none',
    'Subscribe': 'dashboard.subscribeHint',
    'Your recent jobs': 'dashboard.yourRecentJobs',
    'Verified': 'status.verified',
    'Pending': 'status.pending',
    'Rejected': 'status.rejected',
    'Title': 'myJobs.titleCol',
    'Location': 'common.location',
    'Wage': 'jobs.wage',
    'Category': 'jobs.filters.category',
    'Status': 'kyc.status',
    'Vacancies': 'jobs.vacancies',
};

const tr = (s: string): string => (backendKeys[s] ? t(backendKeys[s]) : s);

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

// Tile accent colors (cycled across the stat tiles).
const tileColors = ['#f24711', '#2bc155', '#4a6cf7', '#c544d8'];

// Activity overview chart — real data from the backend, one series per stage.
const ranges = ['Daily', 'Weekly', 'Monthly'] as const;
const rangeLabel: Record<string, string> = { Daily: 'dashboard.daily', Weekly: 'dashboard.weekly', Monthly: 'dashboard.monthly' };
const activeRange = ref<(typeof ranges)[number]>('Monthly');
const legend = [
    { label: 'dashboard.applications', color: '#2bc155' },
    { label: 'dashboard.inProgress', color: '#4a6cf7' },
    { label: 'dashboard.closedSeries', color: '#ff4a55' },
];

const CW = 720;
const CH = 230;
const PAD = 12;

const activeData = computed<RangeData>(() => props.activity[activeRange.value] ?? { labels: [], series: [[], [], []] });
const pointCount = computed(() => activeData.value.labels.length);
// One shared 0-based y-scale so the three series are honestly comparable.
const sharedMax = computed(() => Math.max(1, ...activeData.value.series.flat()));
const rangeTotal = computed(() => activeData.value.series[0]?.reduce((a, b) => a + b, 0) ?? 0);
const hasActivity = computed(() => activeData.value.series.some((s) => s.some((v) => v > 0)));

const xAt = (i: number) => (pointCount.value < 2 ? CW / 2 : PAD + (i / (pointCount.value - 1)) * (CW - PAD * 2));
const yAt = (v: number) => PAD + (1 - v / sharedMax.value) * (CH - PAD * 2);

// Straight polylines (rounded joins) — honest for counts, no spline overshoot.
const seriesPaths = computed(() =>
    activeData.value.series.map((s) => s.map((v, i) => `${i === 0 ? 'M' : 'L'} ${xAt(i).toFixed(1)} ${yAt(v).toFixed(1)}`).join(' ')),
);

// --- Hover crosshair + tooltip ---
const chartWrap = ref<HTMLElement | null>(null);
const hoverIndex = ref<number | null>(null);
const onChartMove = (e: MouseEvent) => {
    const el = chartWrap.value;
    if (!el || pointCount.value < 2) return;
    const rect = el.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    hoverIndex.value = Math.round(ratio * (pointCount.value - 1));
};
const onChartLeave = () => (hoverIndex.value = null);
const hoverLeftPct = computed(() => (hoverIndex.value === null ? 0 : (xAt(hoverIndex.value) / CW) * 100));
const axisTicks = computed(() => {
    const l = activeData.value.labels;
    if (l.length === 0) return [];
    const mid = Math.floor(l.length / 2);
    return [l[0], l[mid], l[l.length - 1]];
});

// Right-rail snapshot derived from the real stats
const numeric = computed(() => props.stats.map((s) => Number(String(s.value).replace(/[^\d.]/g, '')) || 0));
const total = computed(() => numeric.value.reduce((a, b) => a + b, 0));
const maxStat = computed(() => Math.max(1, ...numeric.value));
const donutColors = ['#4a6cf7', '#c544d8', '#f24711', '#2bc155'];
const donutSegments = computed(() => {
    if (total.value === 0) return [];
    let offset = 25;
    return numeric.value.map((v, i) => {
        const pct = (v / total.value) * 100;
        const seg = { pct, color: donutColors[i % donutColors.length], offset };
        offset -= pct;
        return seg;
    });
});

const profileHref = computed(
    () => ({ worker: '/worker/profile', employer: '/employer/profile' })[props.role] ?? dashboard().url,
);
</script>

<template>
    <Head title="Dashboard" />

    <div class="grid flex-1 items-start gap-6 p-4 md:p-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <!-- Main column -->
        <div class="flex min-w-0 flex-col gap-6">
            <!-- Stat tiles -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(stat, i) in stats"
                    :key="stat.label"
                    class="relative overflow-hidden rounded-2xl border bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                >
                    <span class="absolute inset-y-0 left-0 w-1" :style="{ background: tileColors[i % tileColors.length] }" />
                    <div class="text-3xl font-bold tracking-tight tabular-nums">{{ tr(stat.value) }}</div>
                    <div class="mt-1 truncate text-sm font-medium text-muted-foreground">{{ tr(stat.label) }}</div>
                    <div class="mt-3">
                        <span class="inline-flex rounded-md bg-accent px-2 py-0.5 text-[11px] font-semibold text-accent-foreground">{{ tr(stat.hint) }}</span>
                    </div>
                </div>
            </div>

            <!-- Activity chart -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold">{{ $t('dashboard.activityOverview') }}</h2>
                    <div class="flex rounded-xl bg-secondary p-1">
                        <button
                            v-for="r in ranges"
                            :key="r"
                            type="button"
                            class="rounded-lg px-3.5 py-1.5 text-xs font-semibold transition"
                            :class="activeRange === r ? 'bg-primary text-primary-foreground shadow' : 'text-muted-foreground hover:text-foreground'"
                            @click="activeRange = r"
                        >
                            {{ $t(rangeLabel[r]) }}
                        </button>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-5 text-xs font-medium text-muted-foreground">
                    <span v-for="l in legend" :key="l.label" class="inline-flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full" :style="{ background: l.color }"></span>
                        {{ $t(l.label) }}
                    </span>
                </div>

                <div ref="chartWrap" class="relative mt-4 select-none" @mousemove="onChartMove" @mouseleave="onChartLeave">
                    <svg class="w-full" :viewBox="`0 0 ${CW} ${CH}`" fill="none" preserveAspectRatio="none" aria-hidden="true">
                        <line
                            v-for="g in 4"
                            :key="g"
                            x1="0"
                            :x2="CW"
                            :y1="(CH / 5) * g"
                            :y2="(CH / 5) * g"
                            stroke="currentColor"
                            class="text-border"
                            stroke-dasharray="3 7"
                        />
                        <path
                            v-for="(d, i) in seriesPaths"
                            :key="i"
                            :d="d"
                            :stroke="legend[i].color"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>

                    <!-- Hover crosshair, per-series dots, and tooltip -->
                    <template v-if="hoverIndex !== null && hasActivity">
                        <div class="pointer-events-none absolute top-0 bottom-0 w-px bg-foreground/15" :style="{ left: `${hoverLeftPct}%` }" />
                        <div
                            v-for="(s, i) in activeData.series"
                            :key="i"
                            class="pointer-events-none absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 bg-background"
                            :style="{ left: `${hoverLeftPct}%`, top: `${(yAt(s[hoverIndex]) / CH) * 100}%`, borderColor: legend[i].color }"
                        />
                        <div
                            class="pointer-events-none absolute top-2 z-10 -translate-x-1/2 rounded-xl border bg-popover px-3 py-2 shadow-lg"
                            :style="{ left: `${Math.min(82, Math.max(18, hoverLeftPct))}%` }"
                        >
                            <div class="mb-1 text-[11px] font-semibold text-muted-foreground">{{ activeData.labels[hoverIndex] }}</div>
                            <div v-for="(l, i) in legend" :key="i" class="flex items-center gap-1.5 text-xs">
                                <span class="size-2 rounded-full" :style="{ background: l.color }" />
                                <span class="text-muted-foreground">{{ $t(l.label) }}</span>
                                <span class="ml-auto pl-3 font-bold tabular-nums">{{ activeData.series[i][hoverIndex] }}</span>
                            </div>
                        </div>
                    </template>

                    <!-- Empty state -->
                    <div v-if="!hasActivity" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                        <span class="rounded-lg bg-muted px-3 py-1.5 text-xs text-muted-foreground">{{ $t('dashboard.noData') }}</span>
                    </div>
                </div>

                <div class="mt-2 flex justify-between px-1 text-[10px] tabular-nums text-muted-foreground">
                    <span v-for="(tick, i) in axisTicks" :key="i">{{ tick }}</span>
                </div>
                <p class="mt-3 text-xs text-muted-foreground">
                    {{ rangeTotal }} {{ $t('dashboard.applications').toLowerCase() }} · {{ $t(rangeLabel[activeRange]).toLowerCase() }}
                </p>
            </div>

            <!-- Recent table -->
            <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div class="flex items-center justify-between border-b px-5 py-4">
                    <h2 class="text-lg font-bold">{{ tr(table.title) }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th v-for="col in table.columns" :key="col" class="px-5 py-3 font-medium">{{ tr(col) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in table.rows" :key="i" class="border-t transition hover:bg-muted/40">
                                <td v-for="(cell, j) in row" :key="j" class="px-5 py-3.5" :class="j === 0 ? 'font-medium' : 'text-muted-foreground'">
                                    {{ cell }}
                                </td>
                            </tr>
                            <tr v-if="table.rows.length === 0">
                                <td :colspan="table.columns.length" class="px-5 py-12 text-center text-muted-foreground">
                                    {{ $t('dashboard.noData') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right rail -->
        <div class="flex flex-col gap-6">
            <!-- Profile card -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-rose-500 text-2xl font-bold text-white">
                        {{ greeting.charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-lg font-bold">{{ greeting }}</div>
                        <div class="text-sm font-medium capitalize text-primary">{{ $t(`auth.${role}`) }}</div>
                    </div>
                </div>
                <Link
                    :href="profileHref"
                    class="mt-5 flex items-center justify-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow transition hover:opacity-90 active:scale-95"
                >
                    {{ $t('dashboard.updateProfile') }} <ArrowUpRight class="size-4" />
                </Link>
            </div>

            <!-- Snapshot card -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="flex items-center gap-2 text-sm font-bold"><Activity class="size-4 text-primary" /> {{ $t('dashboard.snapshot') }}</h2>

                <div class="mt-5 flex items-center justify-center">
                    <div class="relative">
                        <svg viewBox="0 0 36 36" class="size-36 -rotate-90">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="currentColor" class="text-secondary" stroke-width="4" />
                            <circle
                                v-for="(seg, i) in donutSegments"
                                :key="i"
                                cx="18"
                                cy="18"
                                r="15.9155"
                                fill="none"
                                :stroke="seg.color"
                                stroke-width="4"
                                :stroke-dasharray="`${seg.pct} ${100 - seg.pct}`"
                                :stroke-dashoffset="seg.offset"
                                stroke-linecap="butt"
                            />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold tabular-nums">{{ total }}</span>
                            <span class="text-[11px] text-muted-foreground">{{ $t('dashboard.totalLabel') }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-5 space-y-4">
                    <div v-for="(stat, i) in stats" :key="stat.label">
                        <div class="flex items-center justify-between text-sm">
                            <span class="truncate font-medium text-muted-foreground">{{ tr(stat.label) }}</span>
                            <span class="ml-2 font-bold tabular-nums">{{ tr(stat.value) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div
                                class="h-full rounded-full transition-all"
                                :style="{ width: Math.max(6, (numeric[i] / maxStat) * 100) + '%', background: donutColors[i % donutColors.length] }"
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
