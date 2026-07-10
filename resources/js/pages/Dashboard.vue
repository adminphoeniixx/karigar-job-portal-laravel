<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowUpRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Sparkline from '@/components/Sparkline.vue';
import { areaPath, chartPoints, linePath } from '@/lib/chart';
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

const props = defineProps<{
    greeting: string;
    role: string;
    stats: Stat[];
    table: Table;
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

// Jobick-style tile accents: line color + decorative trend wave
const tiles = [
    { color: '#f24711', wave: [8, 14, 9, 16, 11, 18, 13, 20] },
    { color: '#2bc155', wave: [12, 9, 15, 11, 17, 12, 19, 16] },
    { color: '#4a6cf7', wave: [10, 16, 12, 9, 15, 12, 18, 14] },
    { color: '#c544d8', wave: [14, 10, 16, 12, 18, 13, 17, 20] },
];

// Activity overview chart (Jobick "Vacancy Status" style, decorative trends)
const ranges = ['Daily', 'Weekly', 'Monthly'] as const;
const rangeLabel: Record<string, string> = { Daily: 'dashboard.daily', Weekly: 'dashboard.weekly', Monthly: 'dashboard.monthly' };
const activeRange = ref<(typeof ranges)[number]>('Monthly');
const series: Record<(typeof ranges)[number], number[][]> = {
    Daily: [
        [42, 55, 48, 62, 50, 66, 58, 70, 60, 64, 55, 68],
        [28, 34, 30, 38, 33, 36, 31, 40, 35, 38, 33, 42],
        [18, 24, 20, 27, 22, 30, 24, 28, 21, 26, 23, 29],
    ],
    Weekly: [
        [40, 58, 45, 66, 52, 70, 56, 64, 60, 72, 58, 66],
        [30, 36, 32, 42, 34, 38, 33, 44, 36, 40, 34, 45],
        [20, 26, 22, 30, 24, 32, 25, 29, 22, 28, 24, 31],
    ],
    Monthly: [
        [40, 62, 48, 65, 44, 66, 50, 64, 46, 60, 52, 62],
        [30, 33, 36, 34, 32, 38, 30, 24, 34, 36, 32, 44],
        [28, 30, 26, 25, 30, 24, 28, 36, 26, 30, 27, 31],
    ],
};
const legend = [
    { label: 'dashboard.applications', color: '#2bc155' },
    { label: 'dashboard.inProgress', color: '#4a6cf7' },
    { label: 'dashboard.closedSeries', color: '#ff4a55' },
];
const CW = 720;
const CH = 230;
const chartLines = computed(() => series[activeRange.value].map((s) => linePath(chartPoints(s, CW, CH, 8))));
const chartAreas = computed(() => chartLines.value.map((l) => areaPath(l, CW, CH, 8)));

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
                    class="rounded-2xl border bg-card p-5 shadow-sm transition hover:shadow-md"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-3xl font-bold tracking-tight tabular-nums">{{ tr(stat.value) }}</div>
                            <div class="mt-1 truncate text-sm font-medium text-muted-foreground">{{ tr(stat.label) }}</div>
                        </div>
                        <Sparkline :points="tiles[i % tiles.length].wave" :color="tiles[i % tiles.length].color" :width="88" :height="40" />
                    </div>
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
                <svg class="mt-4 w-full" :viewBox="`0 0 ${CW} ${CH}`" fill="none" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient v-for="(l, i) in legend" :id="`area-${i}`" :key="i" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" :stop-color="l.color" stop-opacity="0.16" />
                            <stop offset="100%" :stop-color="l.color" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <line
                        v-for="g in 4"
                        :key="g"
                        x1="8"
                        :x2="CW - 8"
                        :y1="(CH / 5) * g"
                        :y2="(CH / 5) * g"
                        stroke="currentColor"
                        class="text-border"
                        stroke-dasharray="4 6"
                    />
                    <template v-for="(line, i) in chartLines" :key="i">
                        <path :d="chartAreas[i]" :fill="`url(#area-${i})`" />
                        <path :d="line" :stroke="legend[i].color" stroke-width="3" stroke-linecap="round" />
                    </template>
                </svg>
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
