<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, MapPin, Pencil, Plus, Trash2, Users, UsersRound } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Job {
    id: number;
    title: string;
    status: 'draft' | 'active' | 'closed';
    city: string | null;
    state: string | null;
    vacancies: number;
}

defineProps<{
    jobs: { data: Job[]; links: { url: string | null; label: string; active: boolean }[] };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }] } });

const statusPill: Record<string, string> = {
    active: 'bg-teal-500/10 text-teal-600 ring-teal-500/20 dark:text-teal-300',
    closed: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    draft: 'bg-muted text-muted-foreground ring-border',
};

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
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition hover:opacity-90 active:scale-95"
                >
                    <Plus class="size-4" /> Post a job
                </Link>
            </template>
        </PageHeader>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Title</th>
                            <th class="px-5 py-3 font-medium">Location</th>
                            <th class="px-5 py-3 font-medium">Vacancies</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in jobs.data" :key="job.id" class="border-t transition hover:bg-muted/30">
                            <td class="px-5 py-3.5 font-medium">{{ job.title }}</td>
                            <td class="px-5 py-3.5 text-muted-foreground">
                                <span class="inline-flex items-center gap-1">
                                    <MapPin class="size-3.5" />
                                    {{ [job.city, job.state].filter(Boolean).join(', ') || '—' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center gap-1 text-muted-foreground">
                                    <Users class="size-3.5" /> {{ job.vacancies }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[job.status]">
                                    {{ job.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end gap-1">
                                    <Link
                                        :href="`/employer/jobs/${job.id}/applicants`"
                                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-teal-600 transition hover:bg-teal-500/10 dark:text-teal-300"
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
                            <td colspan="5" class="px-5 py-16 text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <BriefcaseBusiness class="size-7" />
                                </div>
                                <p class="mt-4 font-medium">No jobs posted yet</p>
                                <p class="mt-1 text-sm text-muted-foreground">Click "Post a job" to get started.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
