<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BriefcaseBusiness, FileText, GraduationCap, Plus, RefreshCw, Sparkles, Trash2, UserRound, Wrench } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { type Resume } from '@/components/ResumeUpload.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { commonSkills } from '@/data/skills';

// The server sends null for anything not filled in; inputs want ''.
type Filled<T> = { [K in keyof T]: Exclude<T[K], null> | (null extends T[K] ? '' : never) };

interface WorkRow {
    role: string;
    employer: string | null;
    place: string | null;
    from: string | null;
    to: string | null;
    current: boolean;
    details: string | null;
}

interface BuilderForm {
    language: 'en' | 'hi';
    name: string | null;
    phone: string | null;
    email: string | null;
    city: string | null;
    state: string | null;
    trade: string | null;
    experience_years: number | null;
    skills: string[];
    tools: string | null;
    work: WorkRow[];
    education: string | null;
    training: string | null;
    languages: string[];
    about: string | null;
    achievements: string | null;
    expected_wage: number | null;
    relocate: boolean;
}

type DetailsForm = Omit<Filled<BuilderForm>, 'work'> & { work: Filled<WorkRow>[] };

interface ResumeJob {
    role: string;
    employer: string | null;
    place: string | null;
    period: string | null;
    points: string[];
}

interface BuiltResume {
    language: 'en' | 'hi';
    name: string;
    phone: string | null;
    email: string | null;
    location: string | null;
    expected_wage: string | null;
    headline: string | null;
    summary: string | null;
    skills: string[];
    experience: ResumeJob[];
    education: string[];
    languages: string[];
    extras: string[];
}

const props = defineProps<{
    form: BuilderForm;
    saved: BuiltResume | null;
    resume: Resume | null;
    limits: { jobs: number; skills: number };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'My Profile', href: '/worker/profile' },
            { title: 'AI Resume', href: '/worker/resume/builder' },
        ],
    },
});

const { t } = useI18n();
const page = usePage();

const emptyJob = (): Filled<WorkRow> => ({ role: '', employer: '', place: '', from: '', to: '', current: false, details: '' });

const filled = <T extends object>(o: T): Filled<T> =>
    Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v ?? ''])) as Filled<T>;

const details = reactive<DetailsForm>({
    ...filled(props.form),
    skills: [...props.form.skills],
    languages: [...props.form.languages],
    // One empty job to start from: most karigars have worked somewhere.
    work: props.form.work.length ? props.form.work.map((w) => filled(w)) : [emptyJob()],
});

// The preview, in an edit-friendly shape: list fields as one line per entry.
interface EditableResume {
    headline: string;
    summary: string;
    skills: string[];
    experience: { role: string; employer: string | null; place: string | null; period: string; points: string }[];
    education: string;
    languages: string[];
    extras: string;
    meta: Pick<BuiltResume, 'name' | 'phone' | 'email' | 'location' | 'expected_wage'>;
}

const toEditable = (r: BuiltResume): EditableResume => ({
    headline: r.headline ?? '',
    summary: r.summary ?? '',
    skills: [...r.skills],
    experience: r.experience.map((j) => ({ ...j, period: j.period ?? '', points: j.points.join('\n') })),
    education: r.education.join('\n'),
    languages: [...r.languages],
    extras: r.extras.join('\n'),
    meta: { name: r.name, phone: r.phone, email: r.email, location: r.location, expected_wage: r.expected_wage },
});

const lines = (text: string) =>
    text
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);

const preview = ref<EditableResume | null>(props.saved ? toEditable(props.saved) : null);
const step = ref<'details' | 'preview'>(props.saved ? 'preview' : 'details');

const drafting = ref(false);
const saving = ref(false);
const draftErrors = ref<Record<string, string>>({});
const draftFailed = ref('');

const saveErrors = computed(() => Object.values((page.props.errors ?? {}) as Record<string, string>));

const canDraft = computed(() => !!details.name.trim() && !!details.trade.trim());

const payload = () => ({
    ...details,
    work: details.work.filter((w) => w.role.trim() !== ''),
});

const writeWithAi = async () => {
    if (!canDraft.value || drafting.value) return;

    drafting.value = true;
    draftErrors.value = {};
    draftFailed.value = '';

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const res = await fetch('/worker/resume/builder/draft', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify(payload()),
        });

        if (res.status === 422) {
            // Hand-built by the controller: { errors: { field: [msg] } }.
            const body = await res.json();
            draftErrors.value = Object.fromEntries(Object.entries(body.errors ?? {}).map(([k, v]) => [k, (v as string[])[0]]));
            return;
        }
        if (res.status === 429) {
            draftFailed.value = t('resumeBuilder.tooMany');
            return;
        }
        if (!res.ok) throw new Error(String(res.status));

        preview.value = toEditable((await res.json()).resume);
        step.value = 'preview';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch {
        draftFailed.value = t('resumeBuilder.draftFailed');
    } finally {
        drafting.value = false;
    }
};

const save = () => {
    if (!preview.value) return;
    const p = preview.value;

    router.post(
        '/worker/resume/builder',
        {
            ...payload(),
            resume: {
                headline: p.headline,
                summary: p.summary,
                skills: p.skills,
                experience: p.experience.map((j) => ({ ...j, points: lines(j.points) })),
                education: lines(p.education),
                languages: p.languages,
                extras: lines(p.extras),
            },
        },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
        },
    );
};

const addJob = () => {
    if (details.work.length < props.limits.jobs) details.work.push(emptyJob());
};

const languageSuggestions = ['Hindi', 'English', 'Urdu', 'Bengali', 'Gujarati', 'Marathi', 'Punjabi', 'Rajasthani', 'Tamil', 'Telugu', 'Kannada', 'Odia', 'Bhojpuri'];

const sectionClass = 'rounded-2xl border bg-card p-5 shadow-sm md:p-6';
const headingClass = 'mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground';
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head :title="$t('resumeBuilder.title')" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Sparkles" :title="$t('resumeBuilder.title')" :description="$t('resumeBuilder.subtitle')" />

        <!-- The resume on record, when the builder made it -->
        <div v-if="resume && resume.source === 'ai'" class="flex flex-wrap items-center gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
            <FileText class="size-5 shrink-0 text-emerald-600" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ $t('resumeBuilder.savedTitle') }}</p>
                <p class="text-xs text-muted-foreground">{{ $t('resumeBuilder.savedHint', { when: resume.uploaded_ago }) }}</p>
            </div>
            <a href="/worker/resume" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted">
                <FileText class="size-3.5" /> {{ $t('resumeBuilder.openPdf') }}
            </a>
        </div>

        <!-- Steps -->
        <div class="flex items-center gap-2 text-xs font-semibold">
            <button
                type="button"
                class="rounded-full px-3 py-1.5 transition"
                :class="step === 'details' ? 'bg-primary text-white' : 'bg-muted text-muted-foreground hover:text-foreground'"
                @click="step = 'details'"
            >
                1 · {{ $t('resumeBuilder.stepDetails') }}
            </button>
            <span class="text-muted-foreground">→</span>
            <button
                type="button"
                class="rounded-full px-3 py-1.5 transition disabled:opacity-50"
                :class="step === 'preview' ? 'bg-primary text-white' : 'bg-muted text-muted-foreground hover:text-foreground'"
                :disabled="!preview"
                @click="step = 'preview'"
            >
                2 · {{ $t('resumeBuilder.stepPreview') }}
            </button>
        </div>

        <!-- ───────── Step 1: what the resume needs ───────── -->
        <form v-if="step === 'details'" class="grid items-start gap-5 lg:grid-cols-2" @submit.prevent="writeWithAi">
            <div class="flex flex-col gap-5">
                <section :class="sectionClass">
                    <h2 :class="headingClass"><UserRound class="size-4 text-orange-500" /> {{ $t('resumeBuilder.basics') }}</h2>

                    <div class="mb-4">
                        <Label>{{ $t('resumeBuilder.language') }}</Label>
                        <div class="mt-2 inline-flex rounded-xl border p-1">
                            <button
                                v-for="lang in [{ v: 'en', l: 'English' }, { v: 'hi', l: 'हिंदी' }] as const"
                                :key="lang.v"
                                type="button"
                                class="rounded-lg px-4 py-1.5 text-sm font-semibold transition"
                                :class="details.language === lang.v ? 'bg-primary text-white' : 'text-muted-foreground hover:text-foreground'"
                                @click="details.language = lang.v"
                            >
                                {{ lang.l }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ $t('resumeBuilder.languageHint') }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="rb-name">{{ $t('resumeBuilder.name') }} *</Label>
                            <Input id="rb-name" v-model="details.name" maxlength="100" />
                            <InputError :message="draftErrors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-phone">{{ $t('common.phone') }}</Label>
                            <Input id="rb-phone" v-model="details.phone" maxlength="20" placeholder="+91…" />
                            <InputError :message="draftErrors.phone" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-email">{{ $t('profile.email') }}</Label>
                            <Input id="rb-email" v-model="details.email" type="email" placeholder="you@example.com" />
                            <InputError :message="draftErrors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-city">{{ $t('resumeBuilder.city') }}</Label>
                            <Input id="rb-city" v-model="details.city" maxlength="80" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-state">{{ $t('jobs.filters.state') }}</Label>
                            <Input id="rb-state" v-model="details.state" maxlength="80" />
                        </div>
                    </div>
                </section>

                <section :class="sectionClass">
                    <h2 :class="headingClass"><Wrench class="size-4 text-orange-500" /> {{ $t('resumeBuilder.yourWork') }}</h2>
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="rb-trade">{{ $t('resumeBuilder.trade') }} *</Label>
                            <Input id="rb-trade" v-model="details.trade" maxlength="100" :placeholder="$t('resumeBuilder.tradePlaceholder')" />
                            <InputError :message="draftErrors.trade" />
                        </div>
                        <div class="grid gap-2 sm:w-1/2">
                            <Label for="rb-years">{{ $t('resumeBuilder.experienceYears') }}</Label>
                            <Input id="rb-years" v-model.number="details.experience_years" type="number" min="0" max="60" />
                            <InputError :message="draftErrors.experience_years" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-skills">{{ $t('profile.skills') }}</Label>
                            <SkillTagInput id="rb-skills" v-model="details.skills" :suggestions="commonSkills" :placeholder="$t('resumeBuilder.skillsPlaceholder')" />
                            <InputError :message="draftErrors.skills" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-tools">{{ $t('resumeBuilder.tools') }}</Label>
                            <Input id="rb-tools" v-model="details.tools" maxlength="300" :placeholder="$t('resumeBuilder.toolsPlaceholder')" />
                        </div>
                    </div>
                </section>

                <section :class="sectionClass">
                    <h2 :class="headingClass"><GraduationCap class="size-4 text-orange-500" /> {{ $t('resumeBuilder.educationLanguages') }}</h2>
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="rb-edu">{{ $t('resumeBuilder.education') }}</Label>
                            <Input id="rb-edu" v-model="details.education" maxlength="150" :placeholder="$t('resumeBuilder.educationPlaceholder')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-training">{{ $t('resumeBuilder.training') }}</Label>
                            <Input id="rb-training" v-model="details.training" maxlength="300" :placeholder="$t('resumeBuilder.trainingPlaceholder')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-langs">{{ $t('resumeBuilder.languages') }}</Label>
                            <SkillTagInput id="rb-langs" v-model="details.languages" :suggestions="languageSuggestions" :placeholder="$t('resumeBuilder.languagesPlaceholder')" />
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-5">
                <section :class="sectionClass">
                    <h2 :class="headingClass"><BriefcaseBusiness class="size-4 text-orange-500" /> {{ $t('resumeBuilder.workHistory') }}</h2>
                    <p class="-mt-2 mb-4 text-xs text-muted-foreground">{{ $t('resumeBuilder.workHistoryHint') }}</p>

                    <div class="flex flex-col gap-4">
                        <div v-for="(job, i) in details.work" :key="i" class="rounded-xl border bg-muted/20 p-4">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="text-xs font-semibold text-muted-foreground">{{ $t('resumeBuilder.job', { n: i + 1 }) }}</span>
                                <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:underline" @click="details.work.splice(i, 1)">
                                    <Trash2 class="size-3.5" /> {{ $t('common.remove') }}
                                </button>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`rb-role-${i}`">{{ $t('resumeBuilder.role') }}</Label>
                                    <Input :id="`rb-role-${i}`" v-model="job.role" maxlength="100" :placeholder="$t('resumeBuilder.rolePlaceholder')" />
                                    <InputError :message="draftErrors[`work.${i}.role`]" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`rb-employer-${i}`">{{ $t('resumeBuilder.employer') }}</Label>
                                    <Input :id="`rb-employer-${i}`" v-model="job.employer" maxlength="120" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`rb-place-${i}`">{{ $t('resumeBuilder.place') }}</Label>
                                    <Input :id="`rb-place-${i}`" v-model="job.place" maxlength="80" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`rb-from-${i}`">{{ $t('resumeBuilder.from') }}</Label>
                                    <Input :id="`rb-from-${i}`" v-model="job.from" maxlength="20" placeholder="2019" />
                                </div>
                                <div class="grid gap-1.5">
                                    <Label :for="`rb-to-${i}`">{{ $t('resumeBuilder.to') }}</Label>
                                    <Input :id="`rb-to-${i}`" v-model="job.to" maxlength="20" placeholder="2023" :disabled="job.current" />
                                </div>
                                <label class="flex items-center gap-2 text-sm sm:col-span-2">
                                    <Checkbox :model-value="job.current" @update:model-value="(v: boolean | 'indeterminate') => (job.current = v === true)" />
                                    {{ $t('resumeBuilder.current') }}
                                </label>
                                <div class="grid gap-1.5 sm:col-span-2">
                                    <Label :for="`rb-details-${i}`">{{ $t('resumeBuilder.jobDetails') }}</Label>
                                    <textarea :id="`rb-details-${i}`" v-model="job.details" rows="3" maxlength="600" :class="textareaClass" :placeholder="$t('resumeBuilder.jobDetailsPlaceholder')" />
                                </div>
                            </div>
                        </div>

                        <button
                            v-if="details.work.length < limits.jobs"
                            type="button"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-dashed px-4 py-2.5 text-sm font-semibold text-muted-foreground transition hover:border-orange-500/50 hover:text-foreground"
                            @click="addJob"
                        >
                            <Plus class="size-4" /> {{ details.work.length ? $t('resumeBuilder.addJob') : $t('resumeBuilder.addFirstJob') }}
                        </button>
                    </div>
                </section>

                <section :class="sectionClass">
                    <h2 :class="headingClass"><Sparkles class="size-4 text-orange-500" /> {{ $t('resumeBuilder.more') }}</h2>
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="rb-about">{{ $t('resumeBuilder.about') }}</Label>
                            <textarea id="rb-about" v-model="details.about" rows="3" maxlength="600" :class="textareaClass" :placeholder="$t('resumeBuilder.aboutPlaceholder')" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="rb-achievements">{{ $t('resumeBuilder.achievements') }}</Label>
                            <textarea id="rb-achievements" v-model="details.achievements" rows="2" maxlength="600" :class="textareaClass" :placeholder="$t('resumeBuilder.achievementsPlaceholder')" />
                        </div>
                        <div class="grid gap-2 sm:w-1/2">
                            <Label for="rb-wage">{{ $t('resumeBuilder.expectedWage') }}</Label>
                            <Input id="rb-wage" v-model.number="details.expected_wage" type="number" min="0" placeholder="15000" />
                            <InputError :message="draftErrors.expected_wage" />
                        </div>
                        <label class="flex items-center gap-2 text-sm">
                            <Checkbox :model-value="details.relocate" @update:model-value="(v: boolean | 'indeterminate') => (details.relocate = v === true)" />
                            {{ $t('resumeBuilder.relocate') }}
                        </label>
                    </div>
                </section>
            </div>

            <div class="flex flex-col items-end gap-2 lg:col-span-2">
                <p v-if="draftFailed" class="text-sm text-rose-600">{{ draftFailed }}</p>
                <p v-if="Object.keys(draftErrors).length" class="text-sm text-rose-600">{{ $t('resumeBuilder.fixErrors') }}</p>
                <button
                    type="submit"
                    :disabled="!canDraft || drafting"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    <Sparkles class="size-4" :class="{ 'animate-pulse': drafting }" />
                    {{ drafting ? $t('resumeBuilder.writing') : $t('resumeBuilder.write') }}
                </button>
                <p v-if="!canDraft" class="text-xs text-muted-foreground">{{ $t('resumeBuilder.needNameTrade') }}</p>
            </div>
        </form>

        <!-- ───────── Step 2: check, edit, save ───────── -->
        <div v-else-if="preview" class="flex flex-col gap-4">
            <p class="text-sm text-muted-foreground">{{ $t('resumeBuilder.previewHint') }}</p>

            <article class="mx-auto w-full max-w-3xl rounded-2xl border bg-card p-6 shadow-sm md:p-10">
                <h1 class="text-2xl font-bold">{{ preview.meta.name }}</h1>
                <Input v-model="preview.headline" class="mt-2 border-dashed font-medium text-orange-600" maxlength="150" :placeholder="$t('resumeBuilder.headline')" />
                <p class="mt-2 text-xs text-muted-foreground">
                    {{ [preview.meta.phone, preview.meta.email, preview.meta.location].filter(Boolean).join(' · ') }}
                </p>
                <div class="my-5 border-b-2 border-orange-600/70" />

                <h2 class="mb-2 text-xs font-bold tracking-wider text-orange-600 uppercase">{{ $t('resumeBuilder.summary') }}</h2>
                <textarea v-model="preview.summary" rows="4" maxlength="1200" :class="textareaClass" />

                <h2 class="mt-6 mb-2 text-xs font-bold tracking-wider text-orange-600 uppercase">{{ $t('profile.skills') }}</h2>
                <SkillTagInput v-model="preview.skills" :suggestions="commonSkills" />

                <template v-if="preview.experience.length">
                    <h2 class="mt-6 mb-2 text-xs font-bold tracking-wider text-orange-600 uppercase">{{ $t('resumeBuilder.workHistory') }}</h2>
                    <div v-for="(job, i) in preview.experience" :key="i" class="mb-4 rounded-xl border bg-muted/20 p-4">
                        <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                            <Input v-model="job.role" class="font-semibold" maxlength="100" />
                            <Input v-model="job.period" class="sm:w-40" maxlength="60" :placeholder="$t('resumeBuilder.period')" />
                        </div>
                        <p class="mt-1.5 text-xs text-muted-foreground">{{ [job.employer, job.place].filter(Boolean).join(', ') }}</p>
                        <textarea v-model="job.points" rows="4" :class="[textareaClass, 'mt-2']" />
                        <p class="text-[11px] text-muted-foreground">{{ $t('resumeBuilder.onePerLine') }}</p>
                    </div>
                </template>

                <h2 class="mt-6 mb-2 text-xs font-bold tracking-wider text-orange-600 uppercase">{{ $t('resumeBuilder.educationLanguages') }}</h2>
                <textarea v-model="preview.education" rows="2" :class="textareaClass" :placeholder="$t('resumeBuilder.education')" />
                <SkillTagInput v-model="preview.languages" :suggestions="languageSuggestions" class="mt-2" />

                <h2 class="mt-6 mb-2 text-xs font-bold tracking-wider text-orange-600 uppercase">{{ $t('resumeBuilder.more') }}</h2>
                <textarea v-model="preview.extras" rows="3" :class="textareaClass" />
                <p class="text-[11px] text-muted-foreground">{{ $t('resumeBuilder.onePerLine') }}</p>
                <p v-if="preview.meta.expected_wage" class="mt-2 text-sm">{{ $t('resumeBuilder.expectedSalary') }}: {{ preview.meta.expected_wage }}</p>
            </article>

            <div v-if="saveErrors.length" class="mx-auto w-full max-w-3xl rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                <p v-for="(e, i) in saveErrors" :key="i">{{ e }}</p>
            </div>

            <div class="mx-auto flex w-full max-w-3xl flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted" @click="step = 'details'">
                        <ArrowLeft class="size-4" /> {{ $t('resumeBuilder.editDetails') }}
                    </button>
                    <button
                        type="button"
                        :disabled="drafting || !canDraft"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2 text-sm font-semibold transition hover:bg-muted disabled:opacity-50"
                        @click="writeWithAi"
                    >
                        <RefreshCw class="size-4" :class="{ 'animate-spin': drafting }" /> {{ drafting ? $t('resumeBuilder.writing') : $t('resumeBuilder.writeAgain') }}
                    </button>
                </div>
                <button
                    type="button"
                    :disabled="saving"
                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                    @click="save"
                >
                    <FileText class="size-4" /> {{ saving ? $t('resumeBuilder.saving') : $t('resumeBuilder.save') }}
                </button>
            </div>
            <p v-if="draftFailed" class="mx-auto w-full max-w-3xl text-sm text-rose-600">{{ draftFailed }}</p>
        </div>
    </div>
</template>
