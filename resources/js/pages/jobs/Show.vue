<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, BadgeCheck, Bookmark, Briefcase, Check, Clock, Gift, IndianRupee, MapPin, Phone, Sun, UserPlus, Users, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import JobMap from '@/components/JobMap.vue';
import PublicNav from '@/components/PublicNav.vue';
import ResumeUpload, { type Resume } from '@/components/ResumeUpload.vue';

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
    latitude: string | null;
    longitude: string | null;
    vacancies: number;
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
    canApply: boolean;
    application: { status: string; created_at: string } | null;
    isSaved: boolean;
    resume: Resume | null;
}>();

const page = usePage();
const isAuthed = computed(() => !!page.props.auth?.user);

const registerHref = computed(() => `/worker/register?redirect=/jobs/${props.job.id}`);
const loginHref = computed(() => `/worker/login?redirect=/jobs/${props.job.id}`);

const statusStyles: Record<string, string> = {
    pending: 'border-amber-500/30 bg-amber-500/10 text-amber-700',
    accepted: 'border-primary/30 bg-accent text-accent-foreground',
    rejected: 'border-rose-500/30 bg-rose-500/10 text-rose-700',
    withdrawn: 'border-foreground/15 bg-secondary text-muted-foreground',
};

const wage = computed(() => {
    if (!props.job.wage_min && !props.job.wage_max) return 'Not disclosed';
    const range = [props.job.wage_min, props.job.wage_max].filter(Boolean).join('–');
    return `₹${range}${props.job.wage_type ? ' / ' + props.job.wage_type : ''}`;
});

const canCall = props.job.contact_mode !== 'apply' && !!props.job.contact_phone;
const applyAllowed = props.job.contact_mode !== 'call';

const shiftLabel: Record<string, string> = { day: 'Day shift', night: 'Night shift', rotational: 'Rotational shift' };

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
</script>

<template>
    <Head :title="job.title" />

    <div class="theme-paper bg-noise min-h-screen bg-background text-foreground antialiased">
        <PublicNav>
            <Link href="/jobs" class="link-underline">{{ $t('nav.browseJobs') }}</Link>
            <Link href="/worker/register" class="link-underline">{{ $t('landing.forWorkers') }}</Link>
            <Link href="/employer/register" class="link-underline">{{ $t('landing.forEmployers') }}</Link>
        </PublicNav>

        <main class="mx-auto max-w-[64rem] px-6 py-14 lg:px-10">
            <Link href="/jobs" class="link-underline inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground">
                <ArrowLeft class="size-4" /> {{ $t('jobs.backToJobs') }}
            </Link>

            <!-- Masthead -->
            <div class="mt-8 border-b border-foreground/15 pb-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="label-rule text-primary">{{ job.category || $t('jobs.browseTitle') }}</div>
                    <button
                        v-if="isAuthed"
                        class="inline-flex items-center gap-1.5 rounded-sm border px-3 py-1.5 text-xs font-semibold transition"
                        :class="isSaved ? 'border-primary/40 bg-accent text-accent-foreground' : 'border-foreground/20 hover:bg-foreground/5'"
                        @click="toggleSave"
                    >
                        <Bookmark class="size-3.5" :fill="isSaved ? 'currentColor' : 'none'" /> {{ isSaved ? $t('jobs.savedJob') : $t('jobs.saveJob') }}
                    </button>
                </div>

                <h1 class="display-lg mt-5 max-w-3xl">{{ job.title }}</h1>
                <p class="mt-4 inline-flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                    <MapPin class="size-4" /> {{ [job.city, job.state].filter(Boolean).join(', ') || $t('jobs.locationNA') }}
                    <span class="text-foreground/25">·</span> {{ $t('jobs.by') }} {{ job.employer.name }}
                </p>
            </div>

            <!-- Key facts as a ruled row -->
            <div class="grid grid-cols-2 divide-x divide-foreground/10 border-b border-foreground/10 sm:grid-cols-4">
                <div class="py-7 pr-6">
                    <div class="label-rule text-muted-foreground">{{ $t('jobs.wage') }}</div>
                    <div class="mt-2 text-2xl font-bold tracking-tight">{{ wage }}</div>
                </div>
                <div class="py-7 pl-6 pr-6">
                    <div class="label-rule text-muted-foreground">{{ $t('jobs.vacancies') }}</div>
                    <div class="mt-2 text-2xl font-bold tracking-tight">{{ job.vacancies }}</div>
                </div>
                <div class="py-7 pl-6 pr-6">
                    <div class="label-rule text-muted-foreground">{{ $t('jobs.interested') }}</div>
                    <div class="mt-2 text-sm font-semibold">
                        <span v-if="job.requires_worker_fee" class="inline-flex items-center gap-1.5 text-amber-700"><Wallet class="size-4" /> ₹{{ job.worker_fee_amount }}</span>
                        <span v-else class="inline-flex items-center gap-1.5 text-primary"><BadgeCheck class="size-4" /> {{ $t('jobs.noFee') }}</span>
                    </div>
                </div>
                <div class="py-7 pl-6">
                    <div class="label-rule text-muted-foreground">{{ $t('jobForm.shift') }}</div>
                    <div class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold">
                        <Sun class="size-4 text-primary" /> {{ job.shift ? shiftLabel[job.shift] : '—' }}
                    </div>
                </div>
            </div>

            <div v-if="job.skills?.length" class="flex flex-wrap gap-2 border-b border-foreground/10 py-6">
                <span v-for="s in job.skills" :key="s" class="rounded-sm bg-secondary px-3 py-1 text-xs font-medium text-secondary-foreground">{{ s }}</span>
            </div>

            <div v-if="job.perks?.length" class="flex flex-wrap gap-2 border-b border-foreground/10 py-6">
                <span v-for="perk in job.perks" :key="perk" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <Gift class="size-3.5 text-primary" /> {{ perk }}
                </span>
            </div>

            <div class="grid gap-12 py-10 lg:grid-cols-[1.4fr_1fr]">
                <div>
                    <h2 class="label-rule inline-flex items-center gap-2 text-primary"><Briefcase class="size-3.5" /> {{ $t('jobs.description') }}</h2>
                    <p class="mt-5 whitespace-pre-line leading-relaxed text-foreground/85">{{ job.description }}</p>

                    <div v-if="job.latitude && job.longitude" class="mt-10">
                        <h2 class="label-rule inline-flex items-center gap-2 text-primary"><MapPin class="size-3.5" /> {{ $t('jobs.jobLocation') }}</h2>
                        <div class="mt-5 overflow-hidden rounded-sm">
                            <JobMap :lat="Number(job.latitude)" :lng="Number(job.longitude)" height="300px" />
                        </div>
                    </div>
                </div>

                <!-- Apply rail -->
                <aside class="lg:border-l lg:border-foreground/10 lg:pl-10">
                    <div v-if="canCall" class="border-b border-foreground/10 pb-7">
                        <h3 class="text-base font-bold tracking-tight">{{ $t('jobs.callNow') }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ applyAllowed ? $t('jobs.interested') : $t('jobs.directCall') }}</p>
                        <a :href="`tel:${job.contact_phone}`" class="mt-4 inline-flex items-center gap-2 rounded-sm bg-foreground px-5 py-3 text-sm font-bold text-background transition hover:bg-primary">
                            <Phone class="size-4" /> {{ job.contact_phone }}
                        </a>
                    </div>

                    <!-- GUEST -->
                    <div v-if="!isAuthed && applyAllowed" class="pt-7">
                        <h3 class="text-base font-bold tracking-tight">{{ $t('jobs.interested') }}</h3>
                        <p class="mt-1.5 text-sm text-muted-foreground">{{ $t('landing.forWorkersDesc') }}</p>
                        <div class="mt-5 flex flex-col gap-3">
                            <Link :href="registerHref" class="inline-flex items-center justify-center gap-1.5 rounded-sm bg-primary px-5 py-3 text-sm font-bold text-primary-foreground transition hover:bg-foreground">
                                <UserPlus class="size-4" /> {{ $t('landing.joinFree') }}
                            </Link>
                            <Link :href="loginHref" class="link-underline text-center text-sm font-semibold">{{ $t('jobs.loginToApply') }}</Link>
                        </div>
                    </div>

                    <!-- Already applied -->
                    <div v-else-if="application" class="mt-7 rounded-sm border p-5" :class="statusStyles[application.status] ?? statusStyles.withdrawn">
                        <div class="flex items-center gap-2 text-sm font-bold"><Check class="size-4" /> {{ $t('jobs.applied') }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="inline-flex items-center gap-1.5 capitalize"><Clock class="size-3.5" /> {{ $t(`status.${application.status}`) }}</span>
                            <span class="text-xs opacity-70">{{ application.created_at }}</span>
                        </div>
                        <Link href="/worker/applications" class="link-underline mt-3 inline-flex items-center gap-1 text-sm font-semibold">
                            {{ $t('jobs.trackApplications') }} <ArrowRight class="size-3.5" />
                        </Link>
                    </div>

                    <!-- Non-worker -->
                    <p v-else-if="isAuthed && !canApply" class="pt-7 text-sm text-muted-foreground">{{ $t('jobs.loginToApply') }}</p>

                    <!-- Worker, not applied -->
                    <div v-else-if="isAuthed && applyAllowed" class="pt-7">
                        <button
                            v-if="!showForm"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-sm bg-primary px-6 py-3.5 text-sm font-bold text-primary-foreground transition hover:bg-foreground"
                            @click="showForm = true"
                        >
                            {{ $t('jobs.applyNow') }} <ArrowRight class="size-4" />
                        </button>

                        <form v-else class="space-y-5" @submit.prevent="submitApply">
                            <div>
                                <label class="label-rule block text-muted-foreground">{{ $t('jobs.coverNote') }}</label>
                                <textarea
                                    v-model="form.cover_note"
                                    rows="4"
                                    :placeholder="$t('jobs.coverNotePlaceholder')"
                                    class="mt-2 w-full border-b border-foreground/20 bg-transparent py-2 text-sm outline-none transition placeholder:text-muted-foreground/70 focus:border-primary"
                                ></textarea>
                                <p v-if="form.errors.cover_note" class="mt-1 text-xs text-rose-600">{{ form.errors.cover_note }}</p>
                            </div>
                            <div>
                                <label class="label-rule block text-muted-foreground">{{ $t('jobs.expectedWage') }}</label>
                                <input
                                    v-model="form.expected_wage"
                                    type="number"
                                    min="0"
                                    class="mt-2 w-full border-b border-foreground/20 bg-transparent py-2 text-sm outline-none transition focus:border-primary"
                                />
                                <p v-if="form.errors.expected_wage" class="mt-1 text-xs text-rose-600">{{ form.errors.expected_wage }}</p>
                            </div>
                            <div>
                                <label class="label-rule block text-muted-foreground">{{ $t('resume.title') }}</label>
                                <div class="mt-2"><ResumeUpload :resume="resume" compact /></div>
                            </div>
                            <div class="flex items-center gap-3">
                                <button type="submit" :disabled="form.processing" class="rounded-sm bg-primary px-6 py-3 text-sm font-bold text-primary-foreground transition hover:bg-foreground disabled:opacity-60">
                                    {{ $t('common.submit') }}
                                </button>
                                <button type="button" class="link-underline text-sm font-semibold" @click="showForm = false">
                                    {{ $t('common.cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </aside>
            </div>
        </main>

        <!-- Footer -->
        <footer class="bg-foreground text-background/70">
            <div class="mx-auto flex max-w-[88rem] flex-wrap items-center justify-between gap-4 px-6 py-10 text-sm lg:px-10">
                <div class="text-[18px] text-background">
                    <BrandWordmark tone="plain" />
                </div>
                <div class="flex flex-wrap gap-6">
                    <Link href="/jobs" class="transition hover:text-background">{{ $t('nav.browseJobs') }}</Link>
                    <Link href="/worker/register" class="transition hover:text-background">{{ $t('landing.joinAsWorker') }}</Link>
                    <Link href="/employer/register" class="transition hover:text-background">{{ $t('nav.postJob') }}</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
