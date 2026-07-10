<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Briefcase, Building2, ChartColumn, Download, FileText, HardHat, IndianRupee, ReceiptText } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { indianStates } from '@/data/indianLocations';

interface Tiles {
    workers: number;
    employers: number;
    jobs: number;
    applications: number;
    revenue: string | number | null;
    gst: string | number | null;
}

interface MonthRow {
    label: string;
    jobs: number;
    workers: number;
    employers: number;
}

interface LabelTotal {
    label: string;
    total: number;
}

const props = defineProps<{
    filters: { from: string; to: string; state?: string; category?: string };
    tiles: Tiles;
    monthly: MonthRow[];
    topCities: LabelTotal[];
    topCategories: LabelTotal[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Reports', href: '/admin/reports' }] } });

const page = usePage();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

const form = reactive({
    from: props.filters.from,
    to: props.filters.to,
    state: props.filters.state ?? '',
    category: props.filters.category ?? '',
});

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => ({ ...form }),
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
            router.get('/admin/reports', params, { preserveState: true, preserveScroll: true, replace: true });
        }, 400);
    },
);

const inr = (v: string | number | null) => '₹' + Number(v ?? 0).toLocaleString('en-IN');

const exportUrl = (type: string) => {
    const params = new URLSearchParams(
        Object.fromEntries(Object.entries({ ...form, type }).filter(([, v]) => v !== '')) as Record<string, string>,
    );
    return `/admin/reports/export?${params}`;
};

const tileDefs = computed(() => [
    { label: 'New karigars', value: props.tiles.workers.toLocaleString('en-IN'), icon: HardHat, tone: 'bg-orange-500/10 text-orange-600 dark:text-orange-300' },
    { label: 'New employers', value: props.tiles.employers.toLocaleString('en-IN'), icon: Building2, tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-300' },
    { label: 'Jobs posted', value: props.tiles.jobs.toLocaleString('en-IN'), icon: Briefcase, tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300' },
    { label: 'Applications', value: props.tiles.applications.toLocaleString('en-IN'), icon: FileText, tone: 'bg-purple-500/10 text-purple-600 dark:text-purple-300' },
    { label: 'Revenue (incl. GST)', value: inr(props.tiles.revenue), icon: IndianRupee, tone: 'bg-orange-500/10 text-orange-600 dark:text-orange-300' },
    { label: 'GST collected', value: inr(props.tiles.gst), icon: ReceiptText, tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-300' },
]);

const maxMonthly = computed(() => Math.max(1, ...props.monthly.map((m) => Math.max(m.jobs, m.workers + m.employers))));
const maxCity = computed(() => Math.max(1, ...props.topCities.map((c) => c.total)));
const maxCategory = computed(() => Math.max(1, ...props.topCategories.map((c) => c.total)));
</script>

<template>
    <Head title="Reports" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="ChartColumn" title="Reports" description="Platform performance with data filters" />

        <!-- Filters -->
        <div class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4 shadow-sm">
            <div class="grid gap-1">
                <label class="text-xs font-medium text-muted-foreground">From</label>
                <input v-model="form.from" type="date" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30" />
            </div>
            <div class="grid gap-1">
                <label class="text-xs font-medium text-muted-foreground">To</label>
                <input v-model="form.to" type="date" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30" />
            </div>
            <div class="grid gap-1">
                <label class="text-xs font-medium text-muted-foreground">State</label>
                <select v-model="form.state" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30">
                    <option value="">All states</option>
                    <option v-for="st in indianStates" :key="st" :value="st">{{ st }}</option>
                </select>
            </div>
            <div class="grid gap-1">
                <label class="text-xs font-medium text-muted-foreground">Category</label>
                <select v-model="form.category" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30">
                    <option value="">All categories</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <a v-for="t in ['jobs', 'workers', 'employers', 'revenue']" :key="t" :href="exportUrl(t)"
                    class="inline-flex h-9 items-center gap-1.5 rounded-xl border px-3 text-xs font-semibold capitalize text-muted-foreground transition hover:bg-muted hover:text-foreground">
                    <Download class="size-3.5" /> {{ t }} CSV
                </a>
            </div>
        </div>

        <!-- Tiles -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="t in tileDefs" :key="t.label" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-muted-foreground">{{ t.label }}</span>
                    <span class="flex size-8 items-center justify-center rounded-lg" :class="t.tone"><component :is="t.icon" class="size-4" /></span>
                </div>
                <div class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ t.value }}</div>
            </div>
        </div>

        <!-- Monthly trend -->
        <div class="rounded-2xl border bg-card p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">Monthly trend</h2>
                <div class="flex items-center gap-4 text-xs font-medium text-muted-foreground">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-[#f24711]"></span> Jobs</span>
                    <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-full bg-[#4a6cf7]"></span> Signups</span>
                </div>
            </div>
            <div class="mt-6 flex h-40 items-end gap-3">
                <div v-for="m in monthly" :key="m.label" class="group flex flex-1 flex-col items-center justify-end gap-1">
                    <div class="flex w-full items-end justify-center gap-1" style="height: 128px">
                        <div class="w-1/3 rounded-t bg-[#f24711]/80 transition group-hover:bg-[#f24711]" :style="{ height: Math.max(3, (m.jobs / maxMonthly) * 100) + '%' }" :title="`${m.jobs} jobs`"></div>
                        <div class="w-1/3 rounded-t bg-[#4a6cf7]/80 transition group-hover:bg-[#4a6cf7]" :style="{ height: Math.max(3, ((m.workers + m.employers) / maxMonthly) * 100) + '%' }" :title="`${m.workers + m.employers} signups`"></div>
                    </div>
                    <span class="text-[10px] text-muted-foreground">{{ m.label }}</span>
                </div>
                <p v-if="!monthly.length" class="w-full py-10 text-center text-sm text-muted-foreground">No data in this range.</p>
            </div>
        </div>

        <!-- Breakdown -->
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="text-sm font-semibold">Top cities <span class="font-normal text-muted-foreground">· by jobs posted</span></h2>
                <div class="mt-5 space-y-3">
                    <div v-for="c in topCities" :key="c.label">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ c.label }}</span>
                            <span class="tabular-nums text-muted-foreground">{{ c.total }}</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div class="h-full rounded-full bg-[#f24711]" :style="{ width: (c.total / maxCity) * 100 + '%' }"></div>
                        </div>
                    </div>
                    <p v-if="!topCities.length" class="py-6 text-center text-sm text-muted-foreground">No data in this range.</p>
                </div>
            </div>
            <div class="rounded-2xl border bg-card p-6 shadow-sm">
                <h2 class="text-sm font-semibold">Top categories <span class="font-normal text-muted-foreground">· by jobs posted</span></h2>
                <div class="mt-5 space-y-3">
                    <div v-for="c in topCategories" :key="c.label">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ c.label }}</span>
                            <span class="tabular-nums text-muted-foreground">{{ c.total }}</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-secondary">
                            <div class="h-full rounded-full bg-[#4a6cf7]" :style="{ width: (c.total / maxCategory) * 100 + '%' }"></div>
                        </div>
                    </div>
                    <p v-if="!topCategories.length" class="py-6 text-center text-sm text-muted-foreground">No data in this range.</p>
                </div>
            </div>
        </div>
    </div>
</template>
