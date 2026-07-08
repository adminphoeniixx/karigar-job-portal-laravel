<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Bookmark, Briefcase, Check, Clock, IndianRupee, MapPin, UserPlus, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';

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
    employer: { id: number; name: string };
}

const props = defineProps<{
    job: Job;
    canApply: boolean;
    application: { status: string; created_at: string } | null;
    isSaved: boolean;
}>();

const page = usePage();
const isAuthed = computed(() => !!page.props.auth?.user);

const registerHref = computed(() => `/worker/register?redirect=/jobs/${props.job.id}`);
const loginHref = computed(() => `/worker/login?redirect=/jobs/${props.job.id}`);

const statusStyles: Record<string, string> = {
    pending: 'border-amber-400/30 bg-amber-500/10 text-amber-300',
    accepted: 'border-teal-400/30 bg-teal-500/10 text-teal-300',
    rejected: 'border-rose-400/30 bg-rose-500/10 text-rose-300',
    withdrawn: 'border-white/15 bg-white/5 text-slate-300',
};

const wage = computed(() => {
    if (!props.job.wage_min && !props.job.wage_max) return 'Not disclosed';
    const range = [props.job.wage_min, props.job.wage_max].filter(Boolean).join('–');
    return `₹${range}${props.job.wage_type ? ' / ' + props.job.wage_type : ''}`;
});

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

    <div class="dark relative min-h-screen overflow-hidden bg-[#04100d] text-slate-200 antialiased">
        <!-- Ambient glows -->
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute left-1/2 top-[-10%] h-[460px] w-[720px] -translate-x-1/2 rounded-full bg-teal-600/20 blur-[140px]"></div>
            <div class="absolute right-[-5%] top-[25%] h-[320px] w-[320px] rounded-full bg-cyan-600/15 blur-[120px]"></div>
        </div>

        <!-- Nav -->
        <header class="sticky top-0 z-30 border-b border-white/5 bg-[#04100d]/70 backdrop-blur-xl">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-5 py-3.5">
                <Link href="/jobs" class="flex items-center gap-2.5 text-base font-bold text-white">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/40">K</span>
                    Karigar
                </Link>
                <div class="flex items-center gap-2">
                    <LanguageSwitcher />
                    <Link v-if="!isAuthed" :href="loginHref" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/10">
                        Login
                    </Link>
                    <Link v-else href="/dashboard" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/10">
                        Dashboard
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-5 py-10">
            <Link href="/jobs" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-slate-400 transition hover:text-teal-300">
                <ArrowLeft class="size-4" /> All jobs
            </Link>

            <div class="rounded-3xl border border-white/10 bg-white/[0.03] p-6 backdrop-blur sm:p-8">
                <div class="flex items-start justify-between gap-4">
                    <span v-if="job.category" class="inline-flex items-center rounded-full border border-teal-400/20 bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-300">
                        {{ job.category }}
                    </span>
                    <button
                        v-if="isAuthed"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition"
                        :class="isSaved ? 'border-teal-400/30 bg-teal-500/15 text-teal-300' : 'border-white/15 bg-white/5 text-white hover:bg-white/10'"
                        @click="toggleSave"
                    >
                        <Bookmark class="size-3.5" :fill="isSaved ? 'currentColor' : 'none'" /> {{ isSaved ? 'Saved' : 'Save' }}
                    </button>
                </div>

                <h1 class="mt-4 text-3xl font-extrabold tracking-tight text-white">{{ job.title }}</h1>
                <p class="mt-2 inline-flex items-center gap-1.5 text-sm text-slate-400">
                    <MapPin class="size-4" /> {{ [job.city, job.state].filter(Boolean).join(', ') || 'Location N/A' }}
                    <span class="text-slate-600">·</span> by {{ job.employer.name }}
                </p>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-teal-500/15 text-teal-300"><IndianRupee class="size-5" /></span>
                        <div>
                            <div class="text-xs text-slate-500">Wage</div>
                            <div class="font-semibold text-white">{{ wage }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-cyan-500/15 text-cyan-300"><Users class="size-5" /></span>
                        <div>
                            <div class="text-xs text-slate-500">Vacancies</div>
                            <div class="font-semibold text-white">{{ job.vacancies }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="job.skills?.length" class="mt-6 flex flex-wrap gap-2">
                    <span v-for="s in job.skills" :key="s" class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-slate-300">{{ s }}</span>
                </div>

                <div class="mt-6 border-t border-white/5 pt-6">
                    <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-teal-300"><Briefcase class="size-4" /> Description</h2>
                    <p class="whitespace-pre-line text-sm leading-relaxed text-slate-300">{{ job.description }}</p>
                </div>

                <!-- Apply zone -->
                <div class="mt-8 border-t border-white/5 pt-6">
                    <!-- GUEST: sign up / login to apply -->
                    <div v-if="!isAuthed" class="rounded-2xl border border-teal-400/20 bg-teal-500/[0.06] p-5">
                        <h3 class="text-base font-bold text-white">Interested in this job?</h3>
                        <p class="mt-1 text-sm text-slate-400">Create a free worker account to apply and track your application status.</p>
                        <div class="mt-4 flex flex-wrap gap-3">
                            <Link :href="registerHref" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition hover:opacity-90 active:scale-95">
                                <UserPlus class="size-4" /> Sign up to apply
                            </Link>
                            <Link :href="loginHref" class="inline-flex items-center gap-1.5 rounded-xl border border-white/15 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-white/10">
                                I already have an account
                            </Link>
                        </div>
                    </div>

                    <!-- WORKER, already applied: show status -->
                    <div v-else-if="application" class="rounded-2xl border p-5" :class="statusStyles[application.status] ?? statusStyles.withdrawn">
                        <div class="flex items-center gap-2 text-sm font-bold">
                            <Check class="size-4" /> Application submitted
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="inline-flex items-center gap-1.5 capitalize"><Clock class="size-3.5" /> Status: <b>{{ application.status }}</b></span>
                            <span class="text-xs opacity-70">Applied {{ application.created_at }}</span>
                        </div>
                        <Link href="/worker/applications" class="mt-3 inline-flex items-center gap-1 text-sm font-semibold underline-offset-2 hover:underline">
                            Track all my applications <ArrowRight class="size-3.5" />
                        </Link>
                    </div>

                    <!-- NON-WORKER authed (employer/admin) -->
                    <p v-else-if="!canApply" class="text-sm text-slate-400">You're signed in as a non-worker account, so you can't apply. Log in with a worker account to apply.</p>

                    <!-- WORKER, not applied: apply form -->
                    <div v-else>
                        <button
                            v-if="!showForm"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition hover:opacity-90 active:scale-95"
                            @click="showForm = true"
                        >
                            Apply now <ArrowRight class="size-4" />
                        </button>

                        <form v-else class="space-y-4 rounded-2xl border border-white/10 bg-white/[0.02] p-5" @submit.prevent="submitApply">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-slate-400">Cover note (optional)</label>
                                <textarea
                                    v-model="form.cover_note"
                                    rows="4"
                                    placeholder="Tell the employer why you're a good fit…"
                                    class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:border-teal-400/40 focus:outline-none"
                                ></textarea>
                                <p v-if="form.errors.cover_note" class="mt-1 text-xs text-rose-400">{{ form.errors.cover_note }}</p>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-slate-400">Expected wage (₹, optional)</label>
                                <input
                                    v-model="form.expected_wage"
                                    type="number"
                                    min="0"
                                    class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-white placeholder:text-slate-500 focus:border-teal-400/40 focus:outline-none"
                                />
                                <p v-if="form.errors.expected_wage" class="mt-1 text-xs text-rose-400">{{ form.errors.expected_wage }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-60">
                                    Submit application
                                </button>
                                <button type="button" class="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/5" @click="showForm = false">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-white/5">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3 px-5 py-8 text-sm text-slate-500">
                <div class="flex items-center gap-2 font-bold text-white">
                    <span class="flex size-7 items-center justify-center rounded-lg bg-gradient-to-br from-teal-500 to-cyan-600 text-white">K</span>
                    Karigar
                </div>
                <div class="flex gap-5">
                    <Link href="/jobs" class="transition hover:text-white">Browse jobs</Link>
                    <Link href="/worker/register" class="transition hover:text-white">Join as worker</Link>
                    <Link href="/employer/register" class="transition hover:text-white">Post a job</Link>
                </div>
            </div>
        </footer>
    </div>
</template>
