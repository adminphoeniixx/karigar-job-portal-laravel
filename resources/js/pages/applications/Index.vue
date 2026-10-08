<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, FileText, MapPin, Star } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ApplicationTracker from '@/components/ApplicationTracker.vue';
import PageHeader from '@/components/PageHeader.vue';

interface TrackStep { key: string; state: string; at: string | null; result: string | null }

interface Application {
    id: number;
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    expected_wage: string | null;
    created_at: string;
    shortlisted_at: string | null;
    tracking_steps: TrackStep[];
    job: {
        id: number;
        title: string;
        city: string | null;
        state: string | null;
        created_at: string;
        expires_at: string | null;
        employer: { id: number; name: string };
    };
}

defineProps<{
    applications: { data: Application[]; links: { url: string | null; label: string; active: boolean }[] };
}>();

const { t } = useI18n();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Applications', href: '/worker/applications' }] } });

const statusPill: Record<string, string> = {
    accepted: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    withdrawn: 'bg-muted text-muted-foreground ring-border',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const fmtDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

const expanded = ref<Set<number>>(new Set());
const toggleTrack = (id: number) => {
    expanded.value.has(id) ? expanded.value.delete(id) : expanded.value.add(id);
};

const withdraw = (id: number) => {
    if (window.confirm(t('applications.withdrawConfirm'))) {
        router.delete(`/applications/${id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="My Applications" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="FileText" :title="$t('applications.title')" :description="$t('applications.subtitle')" />

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-3 font-medium sm:px-5">{{ $t('applications.job') }}</th>
                            <!-- On a phone these two fold under the job, so the status stays in view. -->
                            <th class="hidden px-5 py-3 font-medium sm:table-cell">{{ $t('applications.employer') }}</th>
                            <th class="hidden px-5 py-3 font-medium sm:table-cell">{{ $t('applications.applied') }}</th>
                            <th class="hidden px-5 py-3 font-medium sm:table-cell">{{ $t('kyc.status') }}</th>
                            <th class="px-5 py-3 text-right font-medium">{{ $t('common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="a in applications.data" :key="a.id">
                        <tr class="border-t transition hover:bg-muted/30">
                            <td class="px-3 py-3.5 sm:px-5">
                                <Link :href="`/jobs/${a.job.id}`" class="font-medium hover:text-orange-600 dark:hover:text-orange-300">{{ a.job.title }}</Link>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                                    <span class="inline-flex items-center gap-1"><MapPin class="size-3" /> {{ [a.job.city, a.job.state].filter(Boolean).join(', ') || '—' }}</span>
                                    <span>{{ $t('jobs.posted') }} {{ fmtDate(a.job.created_at) }}</span>
                                    <span v-if="a.job.expires_at" class="font-medium text-rose-500 dark:text-rose-400">{{ $t('jobs.expires') }} {{ fmtDate(a.job.expires_at) }}</span>
                                    <span class="sm:hidden">{{ a.job.employer.name }} · {{ $t('applications.applied') }} {{ fmtDate(a.created_at) }}</span>
                                </div>
                                <!-- Phones: the status sits under the job instead of in its own column. -->
                                <div class="mt-2 flex items-center gap-1.5 sm:hidden">
                                    <span
                                    v-if="a.shortlisted_at && a.status === 'pending'"
                                    class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300"
                                >
                                    <Star class="size-3" fill="currentColor" /> {{ $t('applications.shortlisted') }}
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset"
                                    :class="statusPill[a.status]"
                                >
                                    {{ $t(`status.${a.status}`) }}
                                </span>
                                </div>
                            </td>
                            <td class="hidden px-5 py-3.5 text-muted-foreground sm:table-cell">{{ a.job.employer.name }}</td>
                            <td class="hidden px-5 py-3.5 whitespace-nowrap text-muted-foreground sm:table-cell">{{ fmtDate(a.created_at) }}</td>
                            <td class="hidden px-5 py-3.5 sm:table-cell">
                                <!-- One pill: "Shortlisted" while the employer decides, then the decision itself. -->
                                <div class="flex items-center gap-1.5">
                                    <span
                                        v-if="a.shortlisted_at && a.status === 'pending'"
                                        class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300"
                                    >
                                        <Star class="size-3" fill="currentColor" /> {{ $t('applications.shortlisted') }}
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset"
                                        :class="statusPill[a.status]"
                                    >
                                        {{ $t(`status.${a.status}`) }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-3.5 text-right sm:px-5 sm:whitespace-nowrap">
                                <button
                                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-orange-600 transition hover:bg-orange-500/10 dark:text-orange-300"
                                    @click="toggleTrack(a.id)"
                                >
                                    {{ $t('tracker.track') }}
                                    <ChevronDown class="size-3.5 transition-transform" :class="{ 'rotate-180': expanded.has(a.id) }" />
                                </button>
                                <button
                                    v-if="a.status === 'pending'"
                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                                    @click="withdraw(a.id)"
                                >
                                    {{ $t('applications.withdraw') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="expanded.has(a.id)" class="border-t bg-muted/20">
                            <td colspan="5" class="px-5 py-4">
                                <ApplicationTracker :steps="a.tracking_steps" class="max-w-md" />
                            </td>
                        </tr>
                        </template>
                        <tr v-if="applications.data.length === 0">
                            <td colspan="5" class="px-5 py-16 text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                                    <FileText class="size-7" />
                                </div>
                                <p class="mt-4 font-medium">{{ $t('applications.empty') }}</p>
                                <p class="mt-1 text-sm text-muted-foreground">Browse jobs and apply to get started.</p>
                                <Link href="/jobs" class="mt-4 inline-flex rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white">Browse jobs</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
