<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, ArrowUpRight, Database } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { dashboard } from '@/routes';

interface Stat {
    label: string;
    value: string;
    hint: string;
    tone: 'emerald' | 'amber' | 'violet';
    // The page the tile opens, when it has one.
    href?: string;
    // How things stand, for tiles like KYC: good is green, warn amber, bad red.
    state?: 'good' | 'warn' | 'bad' | null;
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

// The employer's "Worker Database" card: contacts the plan opens and unlocks left.
interface DatabaseCard {
    active: boolean;
    plan: string | null;
    contacts: number;
    unlock_limit: number;
    unlocks_used: number;
    unlocks_remaining: number | null;
}

const props = defineProps<{
    greeting: string;
    role: string;
    stats: Stat[];
    table: Table;
    activity: Record<string, RangeData>;
    database?: DatabaseCard;
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
    'Jobs for you': 'dashboard.jobsForYou',
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

// Tile accent colors (cycled across the stat tiles), warm brand ramp.
const tileColors = ['#bf3a16', '#8a6a2f', '#4f6b4a', '#a3502b'];
// A tile with a state wears the state's colour instead (KYC verified → green).
const stateColors = { good: '#059669', warn: '#d97706', bad: '#e11d48' };
const stateText = {
    good: 'text-emerald-700 dark:text-emerald-400',
    warn: 'text-amber-700 dark:text-amber-400',
    bad: 'text-rose-700 dark:text-rose-400',
};
const stateBadge = {
    good: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    warn: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    bad: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
};
const tileColor = (stat: Stat, i: number) => (stat.state ? stateColors[stat.state] : tileColors[i % tileColors.length]);

// Activity overview: real data from the backend, one series per stage, drawn
// as grouped columns. The counts are small and lumpy, and lines over mostly
// empty periods read as noise.
const ranges = ['Daily', 'Weekly', 'Monthly'] as const;
const rangeLabel: Record<string, string> = { Daily: 'dashboard.daily', Weekly: 'dashboard.weekly', Monthly: 'dashboard.monthly' };
const rangeSpan: Record<string, string> = { Daily: 'dashboard.lastDays', Weekly: 'dashboard.lastWeeks', Monthly: 'dashboard.lastMonths' };
const activeRange = ref<(typeof ranges)[number]>('Monthly');
// Teal, ochre, terracotta: checked for colour-blind separation and contrast
// against the card, in light and dark.
const legend = [
    { label: 'dashboard.applications', color: '#00897b' },
    { label: 'dashboard.inProgress', color: '#c4861f' },
    { label: 'dashboard.closedSeries', color: '#bf3a16' },
];

const activeData = computed<RangeData>(() => props.activity[activeRange.value] ?? { labels: [], series: [[], [], []] });
const pointCount = computed(() => activeData.value.labels.length);
const hasActivity = computed(() => activeData.value.series.some((s) => s.some((v) => v > 0)));
const seriesTotals = computed(() => activeData.value.series.map((s) => s.reduce((a, b) => a + b, 0)));

// One shared 0-based y-scale in clean steps (1, 2 or 5 × 10ⁿ), at most four.
const yTicks = computed(() => {
    const max = Math.max(1, ...activeData.value.series.flat());
    const raw = max / 4;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = Math.max(1, [1, 2, 5, 10].map((m) => m * mag).find((m) => m >= raw) ?? 10 * mag);
    const top = Math.ceil(max / step) * step;

    return Array.from({ length: top / step + 1 }, (_, i) => i * step);
});
const yMax = computed(() => yTicks.value[yTicks.value.length - 1]);
const atY = (v: number) => `${(v / yMax.value) * 100}%`;
// On a phone, every other period keeps its label (always the latest one).
const thinLabel = (i: number) => pointCount.value > 8 && (pointCount.value - 1 - i) % 2 === 1;

const hoverIndex = ref<number | null>(null);
const hoverLeftPct = computed(() => (hoverIndex.value === null ? 0 : ((hoverIndex.value + 0.5) / pointCount.value) * 100));

// Right-rail snapshot derived from the real stats
const numeric = computed(() => props.stats.map((s) => Number(String(s.value).replace(/[^\d.]/g, '')) || 0));
const total = computed(() => numeric.value.reduce((a, b) => a + b, 0));
const maxStat = computed(() => Math.max(1, ...numeric.value));
const donutColors = ['#bf3a16', '#c88a2e', '#4f6b4a', '#8a5a3c'];
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
                <component
                    :is="stat.href ? Link : 'div'"
                    v-for="(stat, i) in stats"
                    :key="stat.label"
                    :href="stat.href"
                    class="group relative overflow-hidden rounded-2xl border bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
                >
                    <span class="absolute inset-y-0 left-0 w-1" :style="{ background: tileColor(stat, i) }" />
                    <ArrowUpRight
                        v-if="stat.href"
                        class="absolute top-4 right-4 size-4 text-muted-foreground transition group-hover:text-primary"
                    />
                    <div class="text-3xl font-bold tracking-tight tabular-nums" :class="stat.state ? stateText[stat.state] : ''">{{ tr(stat.value) }}</div>
                    <div class="mt-1 truncate text-sm font-medium text-muted-foreground">{{ tr(stat.label) }}</div>
                    <div class="mt-3">
                        <span
                            class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold"
                            :class="stat.state ? stateBadge[stat.state] : 'bg-accent text-accent-foreground'"
                            >{{ tr(stat.hint) }}</span
                        >
                    </div>
                </component>
            </div>

            <!-- Worker Database: bought as its own plan, no credits -->
            <div v-if="database" class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-5 shadow-sm">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600">
                    <Database class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-lg font-bold">
                        {{ database.active ? $t('dashboard.databaseContacts', { count: database.contacts.toLocaleString('en-IN') }) : $t('dashboard.workerDatabase') }}
                    </div>
                    <div class="text-sm text-muted-foreground">
                        <template v-if="!database.active">{{ $t('dashboard.databaseBuyHint') }}</template>
                        <template v-else>
                            {{
                                database.unlocks_remaining === null
                                    ? $t('dashboard.databaseUnlimited')
                                    : $t('dashboard.databaseUnlocksLeft', { left: database.unlocks_remaining, limit: database.unlock_limit })
                            }}
                            · {{ database.plan }}
                        </template>
                    </div>
                </div>
                <Link
                    href="/subscription#database"
                    class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90"
                >
                    {{
                        !database.active ? $t('dashboard.buyDatabase') : database.unlocks_remaining === 0 ? $t('dashboard.upgradeDatabase') : $t('dashboard.viewPlans')
                    }}
                </Link>
            </div>

            <!-- Activity chart -->
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold">{{ $t('dashboard.activityOverview') }}</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ $t(rangeSpan[activeRange], { n: pointCount }) }}</p>
                    </div>
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

                <!-- The range's totals, which double as the legend -->
                <div class="mt-5 grid grid-cols-3 gap-3">
                    <div v-for="(l, i) in legend" :key="l.label" class="rounded-xl bg-secondary/60 px-3 py-2.5">
                        <div class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <span class="size-2.5 shrink-0 rounded-sm" :style="{ background: l.color }" />
                            <span class="truncate">{{ $t(l.label) }}</span>
                        </div>
                        <div class="mt-1 text-2xl font-bold">{{ seriesTotals[i] }}</div>
                    </div>
                </div>

                <!-- One group of columns per period -->
                <div class="mt-6 flex gap-2" aria-hidden="true">
                    <div class="relative h-44 w-6 shrink-0 text-right text-[10px] tabular-nums text-muted-foreground">
                        <span v-for="tick in yTicks" :key="tick" class="absolute right-0 translate-y-1/2 leading-none" :style="{ bottom: atY(tick) }">{{
                            tick
                        }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="relative h-44" @mouseleave="hoverIndex = null">
                            <div
                                v-for="tick in yTicks"
                                :key="tick"
                                class="absolute inset-x-0 h-px"
                                :class="tick === 0 ? 'bg-border' : 'bg-border/50'"
                                :style="{ bottom: atY(tick) }"
                            />
                            <div class="absolute inset-0 flex">
                                <div
                                    v-for="(label, i) in activeData.labels"
                                    :key="i"
                                    class="flex h-full flex-1 items-end justify-center gap-[2px] rounded-md transition-colors"
                                    :class="hoverIndex === i && hasActivity ? 'bg-foreground/5' : ''"
                                    @mouseenter="hoverIndex = i"
                                >
                                    <div
                                        v-for="(s, k) in activeData.series"
                                        :key="k"
                                        class="w-1.5 rounded-t-[3px] sm:w-2.5"
                                        :style="{ height: atY(s[i]), background: legend[k].color }"
                                    />
                                </div>
                            </div>

                            <div
                                v-if="hoverIndex !== null && hasActivity"
                                class="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-xl border bg-popover px-3 py-2 shadow-lg"
                                :style="{ left: `${Math.min(85, Math.max(15, hoverLeftPct))}%` }"
                            >
                                <div class="mb-1 text-[11px] font-semibold text-muted-foreground">{{ activeData.labels[hoverIndex] }}</div>
                                <div v-for="(l, k) in legend" :key="k" class="flex items-center gap-1.5 text-xs">
                                    <span class="size-2 rounded-sm" :style="{ background: l.color }" />
                                    <span class="text-muted-foreground">{{ $t(l.label) }}</span>
                                    <span class="ml-auto pl-3 font-bold tabular-nums">{{ activeData.series[k][hoverIndex] }}</span>
                                </div>
                            </div>

                            <div v-if="!hasActivity" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <span class="rounded-lg bg-muted px-3 py-1.5 text-xs text-muted-foreground">{{ $t('dashboard.noData') }}</span>
                            </div>
                        </div>
                        <div class="mt-2 flex">
                            <span
                                v-for="(label, i) in activeData.labels"
                                :key="i"
                                class="flex-1 truncate text-center text-[10px] text-muted-foreground"
                                :class="thinLabel(i) ? 'max-sm:invisible' : ''"
                                >{{ label }}</span
                            >
                        </div>
                    </div>
                </div>

                <!-- The same numbers for screen readers -->
                <table class="sr-only">
                    <caption>
                        {{ $t('dashboard.activityOverview') }}
                    </caption>
                    <tr>
                        <th></th>
                        <th v-for="l in legend" :key="l.label">{{ $t(l.label) }}</th>
                    </tr>
                    <tr v-for="(label, i) in activeData.labels" :key="i">
                        <th>{{ label }}</th>
                        <td v-for="(s, k) in activeData.series" :key="k">{{ s[i] }}</td>
                    </tr>
                </table>
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
                                <!-- Phones show the first three columns; the rest from sm up. -->
                                <th v-for="(col, j) in table.columns" :key="col" class="px-3 py-3 font-medium sm:px-5" :class="j > 2 ? 'hidden sm:table-cell' : ''">{{ tr(col) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in table.rows" :key="i" class="border-t transition hover:bg-muted/40">
                                <td v-for="(cell, j) in row" :key="j" class="px-3 py-3.5 sm:px-5" :class="[j === 0 ? 'font-medium' : 'text-muted-foreground', j > 2 ? 'hidden sm:table-cell' : '']">
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
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-primary text-2xl font-bold text-white">
                        {{ greeting.charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-w-0">
                        <div class="truncate text-lg font-bold">{{ greeting }}</div>
                        <!-- The admin pages are English-only; there is no auth.admin key. -->
                        <div class="text-sm font-medium capitalize text-primary">{{ role === 'admin' ? 'Admin' : $t(`auth.${role}`) }}</div>
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
                            <span class="ml-2 font-bold tabular-nums" :class="stat.state ? stateText[stat.state] : ''">{{ tr(stat.value) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div
                                class="h-full rounded-full transition-all"
                                :style="
                                    stat.state
                                        ? { width: '100%', background: stateColors[stat.state] }
                                        : { width: Math.max(6, (numeric[i] / maxStat) * 100) + '%', background: donutColors[i % donutColors.length] }
                                "
                            ></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
