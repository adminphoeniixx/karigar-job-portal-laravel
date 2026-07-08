<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bookmark, MapPin, Trash2 } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Saved {
    id: number;
    job: {
        id: number;
        title: string;
        city: string | null;
        state: string | null;
        category: string | null;
        wage_min: string | null;
        wage_max: string | null;
        wage_type: string | null;
        status: string;
    };
}

defineProps<{
    saved: { data: Saved[]; links: { url: string | null; label: string; active: boolean }[] };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Saved Jobs', href: '/worker/saved' }] } });

const remove = (jobId: number) => {
    router.post(`/jobs/${jobId}/save`, {}, { preserveScroll: true });
};

const wage = (j: Saved['job']) => {
    if (!j.wage_min && !j.wage_max) return 'Not disclosed';
    return `₹${[j.wage_min, j.wage_max].filter(Boolean).join('–')}${j.wage_type ? ' / ' + j.wage_type : ''}`;
};
</script>

<template>
    <Head title="Saved Jobs" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Bookmark" title="Saved Jobs" description="Jobs you've bookmarked" />

        <div v-if="saved.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="s in saved.data" :key="s.id" class="flex flex-col rounded-2xl border bg-card p-5 shadow-sm transition hover:shadow-md">
                <div class="flex items-start justify-between gap-2">
                    <span v-if="s.job.category" class="rounded-full bg-orange-500/10 px-2.5 py-0.5 text-xs font-semibold text-orange-600 dark:text-orange-300">{{ s.job.category }}</span>
                    <button class="text-rose-500 transition hover:text-rose-600" title="Remove" @click="remove(s.job.id)"><Trash2 class="size-4" /></button>
                </div>
                <Link :href="`/jobs/${s.job.id}`" class="mt-3 text-lg font-semibold hover:text-orange-600 dark:hover:text-orange-300">{{ s.job.title }}</Link>
                <div class="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <MapPin class="size-3" /> {{ [s.job.city, s.job.state].filter(Boolean).join(', ') || '—' }}
                </div>
                <div class="mt-3 text-sm font-medium text-foreground">{{ wage(s.job) }}</div>
                <Link :href="`/jobs/${s.job.id}`" class="mt-4 inline-flex justify-center rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted">View job</Link>
            </div>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Bookmark class="size-7" /></div>
            <p class="mt-4 font-medium">No saved jobs</p>
            <p class="mt-1 text-sm text-muted-foreground">Tap the bookmark on any job to save it here.</p>
            <Link href="/jobs" class="mt-4 inline-flex rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-4 py-2 text-sm font-semibold text-white">Browse jobs</Link>
        </div>
    </div>
</template>
