<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, BadgeCheck, Bookmark, Briefcase, Check, Clock, Gift, IndianRupee, MapPin, Phone, Sun, Users, Wallet } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Job {
    id: number;
    title: string;
    description: string;
    category: string | null;
    skills: string[] | null;
    wage_min: string | null;
    wage_max: string | null;
    wage_type: string | null;
    city: string | null;
    state: string | null;
    vacancies: number;
    created_at: string;
    expires_at: string | null;
    contact_mode: 'apply' | 'call' | 'both';
    contact_phone: string | null;
    shift: 'day' | 'night' | 'rotational' | null;
    perks: string[] | null;
    requires_worker_fee: boolean;
    worker_fee_amount: string | null;
    employer: { id: number; name: string };
}

const props = defineProps<{
    job: Job;
    employerRating: { average: number; count: number } | null;
    application: { status: string; created_at: string } | null;
    isSaved: boolean;
}>();

const employerLine = (() => {
    const r = props.employerRating;
    return r && r.count ? `by ${props.job.employer.name} · ★ ${r.average} (${r.count})` : `by ${props.job.employer.name}`;
})();

defineOptions({ layout: { breadcrumbs: [{ title: 'Browse Jobs', href: '/worker/jobs' }, { title: 'Job', href: '#' }] } });

const statusPill: Record<string, string> = {
    pending: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-300',
    accepted: 'border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-300',
    rejected: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-300',
    withdrawn: 'border-border bg-muted text-muted-foreground',
};

const wage = (() => {
    if (!props.job.wage_min && !props.job.wage_max) return 'Not disclosed';
    const range = [props.job.wage_min, props.job.wage_max].filter(Boolean).join('–');
    return `₹${range}${props.job.wage_type ? ' / ' + props.job.wage_type : ''}`;
})();

const canCall = props.job.contact_mode !== 'apply' && !!props.job.contact_phone;
const canApply = props.job.contact_mode !== 'call';

const showForm = ref(false);
const form = useForm({ cover_note: '', expected_wage: '' });

const submitApply = () => {
    form.post(`/jobs/${props.job.id}/apply`, {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
};

const toggleSave = () => router.post(`/jobs/${props.job.id}/save`, {}, { preserveScroll: true });

const shiftLabel: Record<string, string> = { day: 'Day shift', night: 'Night shift', rotational: 'Rotational shift' };

const fmtDate = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
</script>

<template>
    <Head :title="job.title" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Link href="/worker/jobs" class="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">
            <ArrowLeft class="size-4" /> Back to jobs
        </Link>

        <PageHeader :icon="Briefcase" :title="job.title" :description="employerLine">
            <template #action>
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition"
                    :class="isSaved ? 'border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                    @click="toggleSave"
                >
                    <Bookmark class="size-4" :fill="isSaved ? 'currentColor' : 'none'" /> {{ isSaved ? 'Saved' : 'Save' }}
                </button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Details -->
            <div class="lg:col-span-2">
                <div class="rounded-2xl border bg-card p-6 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="job.category" class="rounded-full bg-orange-500/10 px-3 py-1 text-xs font-semibold text-orange-600 dark:text-orange-300">{{ job.category }}</span>
                        <span class="inline-flex items-center gap-1 text-sm text-muted-foreground"><MapPin class="size-4" /> {{ [job.city, job.state].filter(Boolean).join(', ') || 'Location N/A' }}</span>
                        <span class="inline-flex items-center gap-1 text-sm text-muted-foreground"><Clock class="size-4" /> Posted {{ fmtDate(job.created_at) }}</span>
                        <span v-if="job.shift" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-600 dark:text-amber-300"><Sun class="size-3.5" /> {{ shiftLabel[job.shift] }}</span>
                        <span v-if="job.requires_worker_fee" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-600 dark:text-amber-300"><Wallet class="size-3.5" /> Joining fee: ₹{{ job.worker_fee_amount }}</span>
                        <span v-else class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-300"><BadgeCheck class="size-3.5" /> No fee to join</span>
                        <span v-if="job.expires_at" class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-3 py-1 text-xs font-semibold text-rose-600 dark:text-rose-300">Apply by {{ fmtDate(job.expires_at) }}</span>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <div class="flex items-center gap-3 rounded-xl border p-4">
                            <span class="flex size-10 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600 dark:text-orange-300"><IndianRupee class="size-5" /></span>
                            <div><div class="text-xs text-muted-foreground">Wage</div><div class="font-semibold">{{ wage }}</div></div>
                        </div>
                        <div class="flex items-center gap-3 rounded-xl border p-4">
                            <span class="flex size-10 items-center justify-center rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-300"><Users class="size-5" /></span>
                            <div><div class="text-xs text-muted-foreground">Vacancies</div><div class="font-semibold">{{ job.vacancies }}</div></div>
                        </div>
                    </div>

                    <div v-if="job.skills?.length" class="mt-5 flex flex-wrap gap-1.5">
                        <span v-for="s in job.skills" :key="s" class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">{{ s }}</span>
                    </div>

                    <div v-if="job.perks?.length" class="mt-5 border-t pt-5">
                        <h2 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-orange-600 dark:text-orange-300"><Gift class="size-4" /> Perks</h2>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="perk in job.perks" :key="perk" class="rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-300">✓ {{ perk }}</span>
                        </div>
                    </div>

                    <div class="mt-5 border-t pt-5">
                        <h2 class="mb-2 text-sm font-semibold text-orange-600 dark:text-orange-300">Job Description</h2>
                        <p class="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{{ job.description }}</p>
                    </div>
                </div>
            </div>

            <!-- Apply card -->
            <div>
                <div class="sticky top-6 rounded-2xl border bg-card p-6 shadow-sm">
                    <!-- Already applied -->
                    <div v-if="application">
                        <div class="rounded-xl border p-4" :class="statusPill[application.status] ?? statusPill.withdrawn">
                            <div class="flex items-center gap-2 text-sm font-bold"><Check class="size-4" /> Applied</div>
                            <div class="mt-2 inline-flex items-center gap-1.5 text-sm capitalize"><Clock class="size-3.5" /> Status: <b>{{ application.status }}</b></div>
                            <div class="mt-1 text-xs opacity-70">Applied {{ application.created_at }}</div>
                        </div>
                        <Link href="/worker/applications" class="mt-4 inline-flex w-full items-center justify-center gap-1 rounded-xl border px-4 py-2.5 text-sm font-semibold transition hover:bg-muted">
                            Track my applications <ArrowRight class="size-3.5" />
                        </Link>
                    </div>

                    <!-- Apply / call -->
                    <div v-else>
                        <h2 class="text-base font-bold">Interested?</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ canCall && !canApply ? 'Call the employer directly to get this job.' : canCall ? 'Call the employer directly, or apply and track your status here.' : 'Apply now and track your status right here.' }}
                        </p>

                        <a
                            v-if="canCall"
                            :href="`tel:${job.contact_phone}`"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 active:scale-95"
                        >
                            <Phone class="size-4" /> Call now · {{ job.contact_phone }}
                        </a>

                        <button
                            v-if="canApply && !showForm"
                            class="mt-4 inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95"
                            @click="showForm = true"
                        >
                            Apply now <ArrowRight class="size-4" />
                        </button>

                        <form v-else-if="canApply" class="mt-4 space-y-3" @submit.prevent="submitApply">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Cover note (optional)</label>
                                <textarea v-model="form.cover_note" rows="4" placeholder="Why are you a good fit?" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40"></textarea>
                                <p v-if="form.errors.cover_note" class="mt-1 text-xs text-rose-500">{{ form.errors.cover_note }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Expected wage (₹, optional)</label>
                                <input v-model="form.expected_wage" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                                <p v-if="form.errors.expected_wage" class="mt-1 text-xs text-rose-500">{{ form.errors.expected_wage }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" :disabled="form.processing" class="flex-1 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60">Submit</button>
                                <button type="button" class="rounded-xl border px-4 py-2.5 text-sm font-semibold transition hover:bg-muted" @click="showForm = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
