<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, CalendarDays, MapPin, Pencil, Phone, Plus, Search, Trash2, Users, UsersRound, X } from '@lucide/vue';
import { reactive, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Job {
    id: number;
    title: string;
    status: 'draft' | 'active' | 'closed';
    city: string | null;
    state: string | null;
    vacancies: number;
    created_at: string;
    expires_at: string | null;
    contact_mode: 'apply' | 'call' | 'both';
    applications_count: number;
}

const props = defineProps<{
    jobs: { data: Job[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; status?: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }] } });

const statusPill: Record<string, string> = {
    active: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    closed: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    draft: 'bg-muted text-muted-foreground ring-border',
};

const form = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
});

let timer: ReturnType<typeof setTimeout> | undefined;

const run = () => {
    const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get('/employer/jobs', params, { preserveState: true, preserveScroll: true, replace: true, only: ['jobs', 'filters'] });
};

watch(
    () => ({ ...form }),
    () => {
        clearTimeout(timer);
        timer = setTimeout(run, 350);
    },
);

const clearFilters = () => {
    form.q = '';
    form.status = '';
};

const fmtDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const isExpired = (job: Job): boolean => !!job.expires_at && new Date(job.expires_at).getTime() <= Date.now();

const destroy = (id: number) => {
    if (window.confirm('Delete this job?')) {
        router.delete(`/employer/jobs/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="My Jobs" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="BriefcaseBusiness" title="My Jobs" description="Manage your job postings">
            <template #action>
                <Link
                    href="/employer/jobs/create"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95"
                >
                    <Plus class="size-4" /> Post a job
                </Link>
            </template>
        </PageHeader>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card p-3 shadow-sm">
            <div class="flex min-w-52 flex-1 items-center gap-2 rounded-xl bg-accent px-3">
                <Search class="size-4 text-accent-foreground/70" />
                <input
                    v-model="form.q"
                    type="text"
                    placeholder="Search your jobs by title…"
                    class="flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>
            <select
                v-model="form.status"
                class="h-9 rounded-xl border bg-background px-3 text-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20"
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="draft">Draft</option>
                <option value="closed">Closed</option>
                <option value="expired">Expired</option>
            </select>
            <button
                v-if="form.q || form.status"
                type="button"
                class="inline-flex h-9 items-center gap-1 rounded-xl border px-3 text-sm text-muted-foreground transition hover:bg-muted"
                @click="clearFilters"
            >
                <X class="size-4" /> Clear
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Title</th>
                            <th class="px-5 py-3 font-medium">Location</th>
                            <th class="px-5 py-3 font-medium">Applicants</th>
                            <th class="px-5 py-3 font-medium">Posted</th>
                            <th class="px-5 py-3 font-medium">Expires</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in jobs.data" :key="job.id" class="border-t transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-1.5 font-medium">
                                    {{ job.title }}
                                    <span
                                        v-if="job.contact_mode !== 'apply'"
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 ring-1 ring-inset ring-emerald-500/20 dark:text-emerald-300"
                                        :title="job.contact_mode === 'call' ? 'Workers call you directly' : 'Workers can call or apply'"
                                    >
                                        <Phone class="size-2.5" /> {{ job.contact_mode === 'call' ? 'Direct call' : 'Call + Apply' }}
                                    </span>
                                </div>
                                <div class="text-xs text-muted-foreground"><Users class="mr-0.5 inline size-3" /> {{ job.vacancies }} vacancies</div>
                            </td>
                            <td class="px-5 py-3.5 text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    <MapPin class="size-3.5" />
                                    {{ [job.city, job.state].filter(Boolean).join(', ') || '—' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center gap-1 font-medium">
                                    <UsersRound class="size-3.5 text-orange-500" /> {{ job.applications_count }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap text-muted-foreground">
                                <span class="inline-flex items-center gap-1"><CalendarDays class="size-3.5" /> {{ fmtDate(job.created_at) }}</span>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap" :class="isExpired(job) ? 'font-medium text-rose-600 dark:text-rose-400' : 'text-muted-foreground'">
                                {{ fmtDate(job.expires_at) }}
                            </td>
                            <td class="px-5 py-3.5">
                                <span
                                    v-if="isExpired(job)"
                                    class="inline-flex items-center rounded-full bg-rose-500/10 px-2.5 py-0.5 text-xs font-semibold text-rose-600 ring-1 ring-inset ring-rose-500/20 dark:text-rose-300"
                                >
                                    Expired
                                </span>
                                <span v-else class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[job.status]">
                                    {{ job.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    <Link
                                        :href="`/employer/jobs/${job.id}/applicants`"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-orange-600 transition hover:bg-orange-500/10 dark:text-orange-300"
                                    >
                                        <UsersRound class="size-3.5" /> Applicants
                                    </Link>
                                    <Link
                                        :href="`/employer/jobs/${job.id}/edit`"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    >
                                        <Pencil class="size-3.5" /> Edit
                                    </Link>
                                    <button
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                                        @click="destroy(job.id)"
                                    >
                                        <Trash2 class="size-3.5" /> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="jobs.data.length === 0">
                            <td colspan="7" class="px-5 py-16 text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <BriefcaseBusiness class="size-7" />
                                </div>
                                <p class="mt-4 font-medium">{{ form.q || form.status ? 'No jobs match your filters' : 'No jobs posted yet' }}</p>
                                <p class="mt-1 text-sm text-muted-foreground">{{ form.q || form.status ? 'Try changing or clearing the filters.' : 'Click "Post a job" to get started.' }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
