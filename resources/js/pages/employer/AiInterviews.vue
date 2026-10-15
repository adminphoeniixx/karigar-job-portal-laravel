<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, CalendarClock, MapPin, MessageSquare, Phone, PhoneCall } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ScreeningConversation from '@/components/ScreeningConversation.vue';

interface Row {
    id: number;
    status: string;
    status_label: string;
    source: string | null;
    attempt: number;
    scheduled_label: string | null;
    called_label: string | null;
    outcome: string | null;
    outcome_label: string | null;
    summary: string | null;
    has_conversation: boolean;
    failure_reason: string | null;
    proposed_interview_label: string | null;
    awaiting_confirmation: boolean;
    can_cancel: boolean;
    candidate: { application_id: number | null; worker_id: number | null; name: string | null; phone: string | null; location_label: string | null };
    job: { id: number; title: string } | null;
    interview: { at_label: string; mode: string | null } | null;
}

type Filter = 'upcoming' | 'done' | 'all';

const props = defineProps<{
    interviews: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filter: Filter;
    counts: Record<Filter, number>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AI Interviews', href: '/employer/ai-interviews' }] } });

const tabs: { key: Filter; label: string }[] = [
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'done', label: 'Done' },
    { key: 'all', label: 'All' },
];

const show = (filter: Filter) => router.get('/employer/ai-interviews', { filter }, { preserveScroll: true, replace: true });

const cancel = (row: Row) => router.delete(`/employer/screening-calls/${row.id}`, { preserveScroll: true });

// "View conversation" popup: the call whose transcript is open.
const conversationFor = ref<number | null>(null);

const go = (url: string | null) => {
    if (url) router.get(url, {}, { preserveScroll: true });
};

const statusTone: Record<string, string> = {
    scheduled: 'bg-sky-500/10 text-sky-700 ring-sky-500/20 dark:text-sky-300',
    queued: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300',
    dialing: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300',
    in_progress: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300',
    completed: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300',
};

const sourceLabel: Record<string, string> = {
    manual: 'Booked by you',
    auto: 'After AI shortlist',
    retry: 'Retry',
    invite: 'AI database pick',
};
</script>

<template>
    <Head title="AI Interviews" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="PhoneCall" title="AI Interviews" description="Every AI screening call across your jobs: what is booked, and what each call found out" />

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="rounded-full border px-4 py-1.5 font-medium transition"
                :class="props.filter === tab.key ? 'border-primary bg-primary text-white' : 'hover:bg-muted'"
                @click="show(tab.key)"
            >
                {{ tab.label }} ({{ counts[tab.key] }})
            </button>
        </div>

        <div v-if="interviews.data.length" class="flex flex-col gap-3">
            <article v-for="row in interviews.data" :key="row.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ row.candidate.name ?? 'Karigar' }}</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span v-if="row.job" class="inline-flex items-center gap-1"><BriefcaseBusiness class="size-3" /> {{ row.job.title }}</span>
                            <span v-if="row.candidate.location_label" class="inline-flex items-center gap-1"><MapPin class="size-3" /> {{ row.candidate.location_label }}</span>
                            <a v-if="row.candidate.phone" :href="`tel:${row.candidate.phone}`" class="inline-flex items-center gap-1 hover:text-foreground"><Phone class="size-3" /> {{ row.candidate.phone }}</a>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span v-if="row.outcome_label" class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">{{ row.outcome_label }}</span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="statusTone[row.status] ?? 'bg-muted text-muted-foreground ring-border'">
                            {{ row.status_label }}
                        </span>
                    </div>
                </header>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span v-if="row.status === 'scheduled'" class="inline-flex items-center gap-1.5 font-semibold">
                        <CalendarClock class="size-4 text-sky-600" /> Calls at {{ row.scheduled_label }}
                    </span>
                    <span v-else class="inline-flex items-center gap-1.5 text-muted-foreground"><PhoneCall class="size-4" /> {{ row.called_label }}</span>
                    <span v-if="row.source" class="text-xs text-muted-foreground">{{ sourceLabel[row.source] ?? row.source }}<template v-if="row.attempt > 1"> · try {{ row.attempt }}</template></span>
                </div>

                <p v-if="row.summary" class="mt-3 rounded-xl bg-muted/50 px-3 py-2 text-sm text-muted-foreground">{{ row.summary }}</p>
                <p v-else-if="row.failure_reason && row.status !== 'scheduled'" class="mt-3 text-xs text-muted-foreground">Not reached: {{ row.failure_reason.replaceAll('_', ' ') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                    <span v-if="row.interview" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500/10 px-2.5 py-1 font-semibold text-emerald-700 dark:text-emerald-300">
                        <CalendarClock class="size-4" /> Interview: {{ row.interview.at_label }}<template v-if="row.interview.mode"> · {{ row.interview.mode }}</template>
                    </span>
                    <Link
                        v-else-if="row.awaiting_confirmation && row.job"
                        :href="`/employer/jobs/${row.job.id}/applicants`"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-orange-500/10 px-2.5 py-1 font-semibold text-orange-700 dark:text-orange-300"
                    >
                        Offered {{ row.proposed_interview_label }} · confirm →
                    </Link>
                    <button v-if="row.can_cancel" type="button" class="text-xs font-semibold text-rose-600 hover:underline" @click="cancel(row)">Cancel call</button>
                    <button
                        v-if="row.has_conversation"
                        type="button"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-orange-700 hover:underline dark:text-orange-300"
                        @click="conversationFor = row.id"
                    >
                        <MessageSquare class="size-3.5" /> {{ $t('applicants.screening.viewConversation') }}
                    </button>
                </div>
            </article>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><PhoneCall class="size-7" /></div>
            <p class="mt-4 font-medium">{{ filter === 'upcoming' ? 'No AI calls booked' : 'No AI calls yet' }}</p>
            <p class="mt-1 text-sm text-muted-foreground">Use "Call & schedule" on an applicant to ring them now or book a time.</p>
        </div>

        <div v-if="interviews.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
            <button
                v-for="link in interviews.links"
                :key="link.label"
                :disabled="!link.url"
                class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                :class="link.active ? 'border-orange-500 bg-orange-500/10 font-semibold text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                @click="go(link.url)"
                v-html="link.label"
            />
        </div>

        <!-- What was said on a screening call -->
        <ScreeningConversation :call-id="conversationFor" @close="conversationFor = null" />
    </div>
</template>
