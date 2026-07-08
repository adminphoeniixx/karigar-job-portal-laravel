<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, Mail, MapPin, Phone, Star, UsersRound, X } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';

interface Entry {
    id: number;
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    expected_wage: string | null;
    contact_unlocked: boolean;
    shortlisted_at: string;
    applied_at: string;
    job: { id: number; title: string; location: string };
    worker: {
        id: number;
        name: string;
        rating: number;
        skills: string[];
        city: string | null;
        state: string | null;
        experience_years: number | null;
        email: string | null;
        phone: string | null;
    };
}

defineProps<{ applications: Entry[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Shortlisted', href: '/employer/shortlisted' }] } });

const statusPill: Record<string, string> = {
    accepted: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    withdrawn: 'bg-muted text-muted-foreground ring-border',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const remove = (id: number) => {
    router.post(`/employer/applications/${id}/shortlist`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Shortlisted" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Star" title="Shortlisted" description="Candidates you shortlisted across all your jobs" />

        <div v-if="applications.length" class="grid gap-4">
            <div v-for="a in applications" :key="a.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-500 text-sm font-bold text-white">
                                {{ a.worker.name.charAt(0).toUpperCase() }}
                            </span>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold">{{ a.worker.name }}</h3>
                                    <span v-if="a.worker.rating > 0" class="inline-flex items-center gap-0.5 text-sm text-amber-500">
                                        <Star class="size-3.5" fill="currentColor" /> {{ a.worker.rating }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[a.status]">{{ a.status }}</span>
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                    <span class="inline-flex items-center gap-1"><MapPin class="size-3" /> {{ [a.worker.city, a.worker.state].filter(Boolean).join(', ') || '—' }}</span>
                                    <span v-if="a.worker.experience_years != null">{{ a.worker.experience_years }} yrs exp</span>
                                    <span v-if="a.expected_wage">Expects ₹{{ a.expected_wage }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        <div class="inline-flex items-center gap-1 font-medium text-orange-600 dark:text-orange-400"><Star class="size-3.5" fill="currentColor" /> Shortlisted {{ a.shortlisted_at }}</div>
                        <div class="mt-0.5">Applied {{ a.applied_at }}</div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 font-semibold text-accent-foreground">
                        <BriefcaseBusiness class="size-3" /> {{ a.job.title }}<span v-if="a.job.location" class="font-normal"> · {{ a.job.location }}</span>
                    </span>
                    <span v-for="s in a.worker.skills" :key="s" class="rounded-full bg-muted px-2.5 py-1 font-medium text-muted-foreground">{{ s }}</span>
                </div>

                <div v-if="a.contact_unlocked" class="mt-3 flex flex-wrap gap-4 rounded-xl border border-orange-500/20 bg-orange-500/5 p-3 text-sm">
                    <span class="inline-flex items-center gap-1.5"><Mail class="size-4 text-orange-600" /> {{ a.worker.email }}</span>
                    <span v-if="a.worker.phone" class="inline-flex items-center gap-1.5"><Phone class="size-4 text-orange-600" /> {{ a.worker.phone }}</span>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4">
                    <Link
                        :href="`/employer/jobs/${a.job.id}/applicants`"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-700"
                    >
                        <UsersRound class="size-3.5" /> View applicants
                    </Link>
                    <button
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="remove(a.id)"
                    >
                        <X class="size-3.5" /> Remove from shortlist
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Star class="size-7" /></div>
            <p class="mt-4 font-medium">No shortlisted candidates yet</p>
            <p class="mt-1 text-sm text-muted-foreground">Open a job's applicants and hit "Shortlist" on promising candidates.</p>
        </div>
    </div>
</template>
