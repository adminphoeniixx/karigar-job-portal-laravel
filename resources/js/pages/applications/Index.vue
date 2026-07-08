<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, MapPin } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Application {
    id: number;
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    expected_wage: string | null;
    created_at: string;
    job: {
        id: number;
        title: string;
        city: string | null;
        state: string | null;
        employer: { id: number; name: string };
    };
}

defineProps<{
    applications: { data: Application[]; links: { url: string | null; label: string; active: boolean }[] };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Applications', href: '/worker/applications' }] } });

const statusPill: Record<string, string> = {
    accepted: 'bg-teal-500/10 text-teal-600 ring-teal-500/20 dark:text-teal-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    withdrawn: 'bg-muted text-muted-foreground ring-border',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const withdraw = (id: number) => {
    if (window.confirm('Withdraw this application?')) {
        router.delete(`/applications/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="My Applications" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="FileText" title="My Applications" description="Jobs you've applied to" />

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Job</th>
                            <th class="px-5 py-3 font-medium">Employer</th>
                            <th class="px-5 py-3 font-medium">Applied</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in applications.data" :key="a.id" class="border-t transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <Link :href="`/jobs/${a.job.id}`" class="font-medium hover:text-teal-600 dark:hover:text-teal-300">{{ a.job.title }}</Link>
                                <div class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground">
                                    <MapPin class="size-3" /> {{ [a.job.city, a.job.state].filter(Boolean).join(', ') || '—' }}
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-muted-foreground">{{ a.job.employer.name }}</td>
                            <td class="px-5 py-3.5 text-muted-foreground">{{ a.created_at }}</td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[a.status]">
                                    {{ a.status }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <button
                                    v-if="a.status === 'pending'"
                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                                    @click="withdraw(a.id)"
                                >
                                    Withdraw
                                </button>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                        </tr>
                        <tr v-if="applications.data.length === 0">
                            <td colspan="5" class="px-5 py-16 text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <FileText class="size-7" />
                                </div>
                                <p class="mt-4 font-medium">No applications yet</p>
                                <p class="mt-1 text-sm text-muted-foreground">Browse jobs and apply to get started.</p>
                                <Link href="/jobs" class="mt-4 inline-flex rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-4 py-2 text-sm font-semibold text-white">Browse jobs</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
