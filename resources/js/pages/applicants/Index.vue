<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown, FileText, IndianRupee, Lock, Mail, MapPin, MessageSquare, Phone, Send, Sparkles, Star, TriangleAlert, Unlock, Users, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ApplicationTracker from '@/components/ApplicationTracker.vue';
import PageHeader from '@/components/PageHeader.vue';

interface TrackStep { key: string; state: string; at: string | null; result: string | null }

interface AiMatch {
    score: number | null;
    recommendation: string | null;
    summary: string | null;
    matched_skills: string[];
    red_flags: string[];
}

interface Applicant {
    id: number;
    status: 'pending' | 'accepted' | 'rejected' | 'withdrawn';
    cover_note: string | null;
    expected_wage: string | null;
    contact_unlocked: boolean;
    shortlisted: boolean;
    created_at: string;
    tracking_steps: TrackStep[];
    ai: AiMatch | null;
    resume: { name: string | null; uploaded_at: string | null } | null;
    escrow: { id: number; status: string; status_label: string; amount: string; payout_amount: string } | null;
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

const props = defineProps<{
    job: { id: number; title: string };
    applications: Applicant[];
    sort: 'best_match' | 'recent';
    contactUnlocks: { used: number; limit: number };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }, { title: 'Applicants', href: '#' }] } });

const statusPill: Record<string, string> = {
    accepted: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    withdrawn: 'bg-muted text-muted-foreground ring-border',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const setStatus = (id: number, status: 'accepted' | 'rejected') => {
    router.patch(`/employer/applications/${id}`, { status }, { preserveScroll: true });
};

const unlock = (id: number) => {
    router.post(`/employer/applications/${id}/unlock`, {}, { preserveScroll: true });
};

// Opens (or re-opens) the chat thread with this applicant, pinned to this job.
const message = (a: Applicant) => {
    router.post('/messages', { worker_id: a.worker.id, job_id: props.job.id });
};

const toggleShortlist = (id: number) => {
    router.post(`/employer/applications/${id}/shortlist`, {}, { preserveScroll: true });
};

// ── AI match ────────────────────────────────────────────────────────
const scoreTone = (score: number | null) =>
    score === null
        ? 'bg-muted text-muted-foreground ring-border'
        : score >= 80
          ? 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300'
          : score >= 60
            ? 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300'
            : score >= 40
              ? 'bg-muted text-muted-foreground ring-border'
              : 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300';

const setSort = (sort: 'best_match' | 'recent') => {
    router.get(`/employer/jobs/${props.job.id}/applicants`, { sort }, { preserveScroll: true, preserveState: true });
};

// Queues scoring for applicants that never got a score (e.g. they applied
// before AI matching was switched on). Scores land once the queue runs.
const rescore = () => {
    router.post(`/employer/jobs/${props.job.id}/rescore`, {}, { preserveScroll: true });
};

const unscored = computed(() => props.applications.filter((a) => a.ai === null).length);

const expanded = ref<Set<number>>(new Set());
const toggleTrack = (id: number) => {
    expanded.value.has(id) ? expanded.value.delete(id) : expanded.value.add(id);
};

const fund = (a: Applicant) => {
    const suggested = a.expected_wage ?? '';
    const amount = window.prompt(`Amount to hold in escrow for ${a.worker.name} (₹):`, suggested);
    if (amount && Number(amount) > 0) {
        router.post(`/employer/applications/${a.id}/escrow`, { amount: Number(amount) });
    }
};

const releasePayment = (escrowId: number, name: string) => {
    if (window.confirm(`Confirm the job is done and release payment to ${name}?`)) {
        router.post(`/employer/escrows/${escrowId}/release`, {}, { preserveScroll: true });
    }
};

const escrowBadge: Record<string, string> = {
    pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    funded: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
    release_requested: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    released: 'bg-orange-500/10 text-orange-600 dark:text-orange-300',
    refunded: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    disputed: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

// Review modal state
const reviewFor = ref<number | null>(null);
const reviewForm = useForm({ rating: 5, comment: '' });

const openReview = (id: number) => {
    reviewFor.value = id;
    reviewForm.reset();
    reviewForm.rating = 5;
};

const submitReview = () => {
    if (reviewFor.value === null) return;
    reviewForm.post(`/applications/${reviewFor.value}/review`, {
        preserveScroll: true,
        onSuccess: () => (reviewFor.value = null),
    });
};
</script>

<template>
    <Head :title="`Applicants · ${job.title}`" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Users" :title="$t('applicants.title')" :description="job.title">
            <template #action>
                <div class="rounded-xl border bg-card px-4 py-2 text-sm">
                    <span class="text-muted-foreground">{{ $t('applicants.contactUnlocks') }}:</span>
                    <span class="font-semibold">{{ contactUnlocks.used }}<span v-if="contactUnlocks.limit"> / {{ contactUnlocks.limit }}</span></span>
                </div>
            </template>
        </PageHeader>

        <!-- Sort + AI scoring -->
        <div v-if="applications.length" class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-xl border bg-card p-1">
                <button
                    v-for="opt in (['best_match', 'recent'] as const)"
                    :key="opt"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="sort === opt ? 'bg-orange-500/10 text-orange-600 dark:text-orange-300' : 'text-muted-foreground hover:text-foreground'"
                    @click="setSort(opt)"
                >
                    {{ opt === 'best_match' ? $t('applicants.sortBestMatch') : $t('applicants.sortRecent') }}
                </button>
            </div>
            <button
                v-if="unscored"
                class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                @click="rescore"
            >
                <Sparkles class="size-3.5 text-orange-500" /> {{ $t('applicants.scoreUnscored', { count: unscored }) }}
            </button>
        </div>

        <div v-if="applications.length" class="grid gap-4">
            <div v-for="a in applications" :key="a.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-semibold">{{ a.worker.name }}</h3>
                            <span v-if="a.worker.rating > 0" class="inline-flex items-center gap-0.5 text-sm text-amber-500">
                                <Star class="size-3.5" fill="currentColor" /> {{ a.worker.rating }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><MapPin class="size-3" /> {{ [a.worker.city, a.worker.state].filter(Boolean).join(', ') || '—' }}</span>
                            <span v-if="a.worker.experience_years != null">{{ a.worker.experience_years }} {{ $t('applicants.yrsExp') }}</span>
                            <span>{{ $t('applications.applied') }} {{ a.created_at }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            v-if="a.shortlisted"
                            class="inline-flex items-center gap-1 rounded-full bg-orange-500/10 px-2.5 py-0.5 text-xs font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300"
                        >
                            <Star class="size-3" fill="currentColor" /> {{ $t('applicants.shortlisted') }}
                        </span>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[a.status]">{{ $t(`status.${a.status}`) }}</span>
                    </div>
                </div>

                <div v-if="a.worker.skills.length" class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="s in a.worker.skills" :key="s" class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">{{ s }}</span>
                </div>

                <!-- AI match -->
                <div v-if="a.ai" class="mt-3 rounded-xl border bg-muted/30 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ring-inset" :class="scoreTone(a.ai.score)">
                            <Sparkles class="size-3" /> {{ a.ai.score }}/100
                        </span>
                        <span v-if="a.ai.recommendation" class="text-xs font-semibold text-muted-foreground">
                            {{ $t(`applicants.ai.${a.ai.recommendation}`) }}
                        </span>
                    </div>
                    <p v-if="a.ai.summary" class="mt-2 text-sm text-muted-foreground">{{ a.ai.summary }}</p>
                    <div v-if="a.ai.matched_skills.length" class="mt-2 flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted-foreground">{{ $t('applicants.ai.matched') }}:</span>
                        <span v-for="s in a.ai.matched_skills" :key="s" class="rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-medium text-orange-600 dark:text-orange-300">{{ s }}</span>
                    </div>
                    <div v-if="a.ai.red_flags.length" class="mt-2 flex flex-wrap items-center gap-1.5">
                        <TriangleAlert class="size-3.5 text-rose-500" />
                        <span v-for="f in a.ai.red_flags" :key="f" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-medium text-rose-600 dark:text-rose-300">{{ f }}</span>
                    </div>
                </div>
                <p v-else class="mt-3 text-xs text-muted-foreground">{{ $t('applicants.ai.notScored') }}</p>

                <p v-if="a.cover_note" class="mt-3 rounded-xl bg-muted/50 p-3 text-sm text-muted-foreground">{{ a.cover_note }}</p>
                <p v-if="a.expected_wage" class="mt-2 text-sm"><span class="text-muted-foreground">{{ $t('applicants.expectedWage') }}:</span> <span class="font-medium">₹{{ a.expected_wage }}</span></p>

                <!-- Contact -->
                <div class="mt-3">
                    <div v-if="a.contact_unlocked" class="flex flex-wrap gap-4 rounded-xl border border-orange-500/20 bg-orange-500/5 p-3 text-sm">
                        <span class="inline-flex items-center gap-1.5"><Mail class="size-4 text-orange-600" /> {{ a.worker.email }}</span>
                        <span v-if="a.worker.phone" class="inline-flex items-center gap-1.5"><Phone class="size-4 text-orange-600" /> {{ a.worker.phone }}</span>
                    </div>
                    <button
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition hover:bg-muted"
                        @click="unlock(a.id)"
                    >
                        <Lock class="size-3.5" /> {{ $t('applicants.unlockContact') }}
                    </button>
                </div>

                <!-- Actions -->
                <div class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4">
                    <button
                        v-if="a.status !== 'withdrawn'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                        :class="a.shortlisted
                            ? 'bg-orange-500/10 text-orange-600 ring-1 ring-inset ring-orange-500/30 hover:bg-orange-500/20 dark:text-orange-300'
                            : 'border text-muted-foreground hover:bg-muted hover:text-foreground'"
                        @click="toggleShortlist(a.id)"
                    >
                        <Star class="size-3.5" :fill="a.shortlisted ? 'currentColor' : 'none'" />
                        {{ a.shortlisted ? $t('applicants.shortlisted') : $t('applicants.shortlist') }}
                    </button>
                    <button
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        @click="message(a)"
                    >
                        <MessageSquare class="size-3.5" /> {{ $t('applicants.message') }}
                    </button>
                    <a
                        v-if="a.resume"
                        :href="`/employer/applications/${a.id}/resume`"
                        target="_blank"
                        :title="a.resume.name ?? ''"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    >
                        <FileText class="size-3.5" /> {{ $t('applicants.resume') }}
                    </a>
                    <template v-if="a.status === 'pending'">
                        <button class="inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-700" @click="setStatus(a.id, 'accepted')">
                            <Check class="size-3.5" /> {{ $t('applicants.accept') }}
                        </button>
                        <button class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:hover:bg-rose-500/10" @click="setStatus(a.id, 'rejected')">
                            <X class="size-3.5" /> {{ $t('applicants.reject') }}
                        </button>
                    </template>
                    <button
                        v-if="a.status === 'accepted'"
                        class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted"
                        @click="openReview(a.id)"
                    >
                        <Star class="size-3.5" /> {{ $t('applicants.leaveReview') }}
                    </button>

                    <!-- Escrow payment -->
                    <template v-if="a.status === 'accepted'">
                        <button
                            v-if="!a.escrow || a.escrow.status === 'pending'"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90"
                            @click="fund(a)"
                        >
                            <IndianRupee class="size-3.5" /> {{ a.escrow?.status === 'pending' ? 'Complete payment' : 'Fund payment' }}
                        </button>
                        <button
                            v-else-if="a.escrow.status === 'funded'"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-700"
                            @click="releasePayment(a.escrow.id, a.worker.name)"
                        >
                            <Send class="size-3.5" /> Release ₹{{ a.escrow.payout_amount }}
                        </button>
                        <span
                            v-else-if="a.escrow"
                            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="escrowBadge[a.escrow.status]"
                        >
                            {{ a.escrow.status_label }}
                        </span>
                    </template>

                    <Unlock v-if="a.contact_unlocked" class="ml-auto size-4 text-orange-500" />

                    <button
                        class="ml-auto inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-orange-600 transition hover:bg-orange-500/10 dark:text-orange-300"
                        @click="toggleTrack(a.id)"
                    >
                        {{ $t('tracker.track') }}
                        <ChevronDown class="size-3.5 transition-transform" :class="{ 'rotate-180': expanded.has(a.id) }" />
                    </button>
                </div>

                <div v-if="expanded.has(a.id)" class="mt-4 border-t pt-4">
                    <ApplicationTracker :steps="a.tracking_steps" class="max-w-md" />
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Users class="size-7" /></div>
            <p class="mt-4 font-medium">{{ $t('applicants.empty') }}</p>
            <p class="mt-1 text-sm text-muted-foreground">{{ $t('applicants.emptyHint') }}</p>
        </div>
    </div>

    <!-- Review modal -->
    <div v-if="reviewFor !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="reviewFor = null">
        <div class="w-full max-w-md rounded-2xl border bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold">Leave a review</h3>
            <div class="mt-4 flex items-center gap-1">
                <button v-for="n in 5" :key="n" type="button" @click="reviewForm.rating = n">
                    <Star class="size-7 transition" :class="n <= reviewForm.rating ? 'text-amber-400' : 'text-muted-foreground/30'" :fill="n <= reviewForm.rating ? 'currentColor' : 'none'" />
                </button>
            </div>
            <textarea v-model="reviewForm.comment" rows="4" placeholder="Share your experience…" class="mt-4 w-full rounded-xl border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"></textarea>
            <p v-if="reviewForm.errors.rating" class="mt-1 text-xs text-rose-500">{{ reviewForm.errors.rating }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button class="rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted" @click="reviewFor = null">Cancel</button>
                <button :disabled="reviewForm.processing" class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60" @click="submitReview">Submit</button>
            </div>
        </div>
    </div>
</template>
