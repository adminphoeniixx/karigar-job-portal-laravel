<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Briefcase, Check, Eye, EyeOff, LoaderCircle, Search, ShieldAlert, ShieldCheck, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Row {
    id: number;
    title: string;
    employer: string | null;
    city: string | null;
    state: string | null;
    status: string;
    created_at: string;
    description: string | null;
    // Held by the AI safety check: pending (waiting for you), approved, rejected.
    moderation_status: 'checking' | 'pending' | 'approved' | 'rejected' | null;
    moderation_reasons: string[];
    moderation_note: string | null;
    // ai | phrases (the model was down: only the phrase list ran) | null
    moderation_method: 'ai' | 'phrases' | null;
    moderated_by_admin: boolean;
}

const props = defineProps<{
    jobs: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; status?: string };
    reviewCount: number;
    checkingCount: number;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Job Moderation', href: '/admin/jobs' }] } });

const q = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout>;
watch([q, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/jobs', { q: q.value || undefined, status: status.value || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const toggle = (j: Row) => {
    const down = j.status === 'active';
    if (window.confirm(down ? `Take down "${j.title}"?` : `Restore "${j.title}"?`)) {
        router.post(`/admin/jobs/${j.id}/toggle`, {}, { preserveScroll: true });
    }
};

const approve = (j: Row) => router.post(`/admin/jobs/${j.id}/approve`, {}, { preserveScroll: true });

const reject = (j: Row) => {
    const note = window.prompt(`Why is "${j.title}" rejected? The employer sees this.`);
    if (note) router.post(`/admin/jobs/${j.id}/reject`, { note }, { preserveScroll: true });
};

const statusBadge: Record<string, string> = {
    active: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    closed: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    draft: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="Job Moderation" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Briefcase" title="Job Moderation" description="Review and take down job listings across all employers" />

        <!-- Filters -->
        <div class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:flex-row">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input v-model="q" placeholder="Search job title…" class="w-full rounded-xl border bg-background py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
            </div>
            <select v-model="status" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                <option value="">All statuses</option>
                <option value="review">Waiting for review ({{ reviewCount }})</option>
                <option value="checking">Safety check running ({{ checkingCount }})</option>
                <option value="active">Active</option>
                <option value="closed">Closed</option>
                <option value="draft">Draft</option>
            </select>
        </div>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-for="j in jobs.data" :key="j.id" class="flex items-center justify-between gap-3 border-b px-5 py-3.5 last:border-0">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium">{{ j.title }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="statusBadge[j.status]">{{ j.status }}</span>
                        <span v-if="j.moderation_status === 'pending'" class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:text-sky-300">
                            <ShieldAlert class="size-3" /> Review
                        </span>
                        <span v-else-if="j.moderation_status === 'rejected'" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600">Rejected</span>
                        <span v-else-if="j.moderation_status === 'checking'" class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:text-sky-300">
                            <LoaderCircle class="size-3 animate-spin" /> Checking
                        </span>
                        <span
                            v-else-if="j.moderation_status === 'approved'"
                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="j.moderation_method === 'phrases' && !j.moderated_by_admin ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                            :title="j.moderation_method === 'phrases' && !j.moderated_by_admin ? 'The AI was unavailable: only the phrase list checked this job' : undefined"
                        >
                            <ShieldCheck class="size-3" /> {{ j.moderated_by_admin ? 'Approved by admin' : j.moderation_method === 'phrases' ? 'Basic check only' : 'AI checked' }}
                        </span>
                    </div>
                    <ul v-if="j.moderation_status === 'pending' && j.moderation_reasons.length" class="mt-1 list-inside list-disc text-xs text-sky-800 dark:text-sky-300">
                        <li v-for="r in j.moderation_reasons" :key="r">{{ r }}</li>
                    </ul>
                    <p v-if="j.moderation_status === 'pending' && j.description" class="mt-1 line-clamp-2 max-w-2xl text-xs text-muted-foreground">{{ j.description }}</p>
                    <div class="truncate text-xs text-muted-foreground">
                        {{ j.employer ?? 'Unknown' }} · {{ [j.city, j.state].filter(Boolean).join(', ') || 'No location' }} · {{ j.created_at }}
                    </div>
                </div>
                <div class="flex shrink-0 gap-1">
                    <template v-if="j.moderation_status === 'pending'">
                        <button class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-500/10" @click="approve(j)">
                            <Check class="size-3.5" /> Approve
                        </button>
                        <button class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10" @click="reject(j)">
                            <X class="size-3.5" /> Reject
                        </button>
                    </template>
                    <button
                        v-else-if="j.status === 'active'"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                        @click="toggle(j)"
                    >
                        <EyeOff class="size-3.5" /> Take down
                    </button>
                    <button
                        v-else-if="j.status !== 'draft'"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-orange-600 transition hover:bg-orange-500/10 dark:text-orange-400"
                        @click="toggle(j)"
                    >
                        <Eye class="size-3.5" /> Restore
                    </button>
                </div>
            </div>
            <div v-if="jobs.data.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">No jobs match these filters.</div>
        </div>

        <!-- Pagination -->
        <div v-if="jobs.links.length > 3" class="flex flex-wrap justify-center gap-1">
            <Link
                v-for="(l, i) in jobs.links"
                :key="i"
                :href="l.url ?? ''"
                preserve-scroll
                class="rounded-lg px-3 py-1.5 text-sm transition"
                :class="[l.active ? 'bg-orange-500 text-white' : l.url ? 'border hover:bg-muted' : 'cursor-default text-muted-foreground opacity-50']"
                v-html="l.label"
            />
        </div>
    </div>
</template>
