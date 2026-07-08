<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Activity, TrendingUp } from '@lucide/vue';
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

defineProps<{
    greeting: string;
    role: string;
    stats: Stat[];
    table: Table;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const toneRing: Record<Stat['tone'], string> = {
    emerald: 'from-teal-500/15 to-cyan-500/5 text-teal-600 dark:text-teal-300',
    amber: 'from-rose-500/15 to-orange-500/5 text-rose-600 dark:text-rose-300',
    violet: 'from-cyan-500/15 to-sky-500/5 text-cyan-600 dark:text-cyan-300',
};

const toneChip: Record<Stat['tone'], string> = {
    emerald: 'bg-gradient-to-br from-teal-500 to-cyan-600',
    amber: 'bg-gradient-to-br from-rose-500 to-orange-500',
    violet: 'bg-gradient-to-br from-cyan-500 to-sky-600',
};
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <!-- Hero banner -->
        <div class="relative overflow-hidden rounded-3xl border bg-gradient-to-br from-teal-600 to-cyan-700 p-6 text-white shadow-lg shadow-teal-900/20 md:p-8">
            <div class="pointer-events-none absolute -right-10 -top-10 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-16 right-24 h-40 w-40 rounded-full bg-rose-400/20 blur-2xl"></div>
            <div class="relative">
                <div class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold capitalize backdrop-blur">
                    <Activity class="size-3.5" /> {{ role }}
                </div>
                <h1 class="mt-3 text-2xl font-bold tracking-tight md:text-3xl">Welcome, {{ greeting }} 👋</h1>
                <p class="mt-1 text-sm text-teal-50/80">Here's what's happening on your account today.</p>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="stat in stats"
                :key="stat.label"
                class="group relative overflow-hidden rounded-2xl border bg-gradient-to-br p-5 shadow-sm transition hover:shadow-md"
                :class="toneRing[stat.tone]"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <div class="text-3xl font-bold tracking-tight text-foreground">{{ stat.value }}</div>
                        <div class="mt-1 text-sm font-medium text-foreground">{{ stat.label }}</div>
                    </div>
                    <span class="flex size-10 items-center justify-center rounded-xl text-white shadow-md" :class="toneChip[stat.tone]">
                        <TrendingUp class="size-5" />
                    </span>
                </div>
                <div class="mt-3 text-xs text-muted-foreground">{{ stat.hint }}</div>
            </div>
        </div>

        <!-- Recent table -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-5 py-4">
                <h2 class="font-semibold">{{ table.title }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th v-for="col in table.columns" :key="col" class="px-5 py-3 font-medium">{{ col }}</th>
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
                                No data yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
