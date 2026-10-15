<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Camera,
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    FileText,
    GraduationCap,
    Lightbulb,
    LoaderCircle,
    Palette,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
    UserRound,
    Wrench,
} from '@lucide/vue';
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PdfPreview from '@/components/PdfPreview.vue';
import { type Resume } from '@/components/ResumeUpload.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { commonSkills } from '@/data/skills';

type Template = 'modern' | 'classic' | 'bold';

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
    template: Template;
    accent: string;
    show_photo: boolean;
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
    templates: Template[];
    accents: string[];
    photoUrl: string | null;
    verified: boolean;
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

// ───────────────────────── form state ─────────────────────────

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

// The resume being edited: list fields as one line per entry.
interface EditableResume {
    headline: string;
    summary: string;
    skills: string[];
    experience: { role: string; employer: string | null; place: string | null; period: string; points: string }[];
    education: string;
    languages: string[];
    extras: string;
}

const toEditable = (r: BuiltResume): EditableResume => ({
    headline: r.headline ?? '',
    summary: r.summary ?? '',
    skills: [...r.skills],
    experience: r.experience.map((j) => ({ ...j, period: j.period ?? '', points: j.points.join('\n') })),
    education: r.education.join('\n'),
    languages: [...r.languages],
    extras: r.extras.join('\n'),
});

const lines = (text: string) =>
    text
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);

const edit = ref<EditableResume | null>(props.saved ? toEditable(props.saved) : null);

// ───────────────────────── steps ─────────────────────────

type Step = 1 | 2 | 3 | 4;
const step = ref<Step>(props.saved ? 4 : 1);

const steps = computed(() => [
    { n: 1 as Step, label: t('resumeBuilder.steps.about'), icon: UserRound },
    { n: 2 as Step, label: t('resumeBuilder.steps.work'), icon: Wrench },
    { n: 3 as Step, label: t('resumeBuilder.steps.more'), icon: GraduationCap },
    { n: 4 as Step, label: t('resumeBuilder.steps.design'), icon: Palette },
]);

const stepErrors = ref<Record<string, string>>({});

const go = (n: Step) => {
    if (n === 4 && !edit.value) return;
    // Moving forward checks what the steps being passed require.
    if (n > step.value) {
        if (step.value === 1 && !details.name.trim()) {
            stepErrors.value = { name: t('resumeBuilder.required') };
            return;
        }
        if (n > 2 && !details.trade.trim()) {
            stepErrors.value = { trade: t('resumeBuilder.required') };
            step.value = 2;
            return;
        }
    }
    stepErrors.value = {};
    step.value = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

// ───────────────────────── strength meter ─────────────────────────

const checks = computed(() => [
    { done: !!props.photoUrl && details.show_photo, tip: t('resumeBuilder.tips.photo') },
    { done: !!details.trade.trim(), tip: t('resumeBuilder.tips.trade') },
    { done: details.experience_years !== '', tip: t('resumeBuilder.tips.years') },
    { done: details.skills.length >= 3, tip: t('resumeBuilder.tips.skills') },
    { done: details.work.some((w) => w.role.trim() && w.details.trim().length >= 20), tip: t('resumeBuilder.tips.work') },
    { done: !!details.tools.trim(), tip: t('resumeBuilder.tips.tools') },
    { done: !!(details.education.trim() || details.training.trim()), tip: t('resumeBuilder.tips.education') },
    { done: details.languages.length > 0, tip: t('resumeBuilder.tips.languages') },
    { done: !!(details.about.trim() || details.achievements.trim()), tip: t('resumeBuilder.tips.extra') },
]);

const strength = computed(() => Math.round((checks.value.filter((c) => c.done).length / checks.value.length) * 100));
const strengthLabel = computed(() =>
    strength.value >= 80 ? t('resumeBuilder.strength.strong') : strength.value >= 50 ? t('resumeBuilder.strength.good') : t('resumeBuilder.strength.basic'),
);
const strengthColor = computed(() => (strength.value >= 80 ? 'bg-emerald-600' : strength.value >= 50 ? 'bg-amber-500' : 'bg-primary'));

// ───────────────────────── AI draft ─────────────────────────

const drafting = ref(false);
const draftErrors = ref<Record<string, string>>({});
const draftFailed = ref('');
const loaderLine = ref(0);
let loaderTimer: ReturnType<typeof setInterval> | undefined;

const loaderLines = computed(() => [t('resumeBuilder.loader.reading'), t('resumeBuilder.loader.summary'), t('resumeBuilder.loader.points'), t('resumeBuilder.loader.design')]);

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const postJson = (url: string, body: unknown) =>
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json, application/pdf',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });

const answers = () => ({
    ...details,
    work: details.work.filter((w) => w.role.trim() !== ''),
});

const writeWithAi = async () => {
    if (drafting.value) return;
    if (!details.name.trim()) {
        stepErrors.value = { name: t('resumeBuilder.required') };
        step.value = 1;
        return;
    }
    if (!details.trade.trim()) {
        stepErrors.value = { trade: t('resumeBuilder.required') };
        step.value = 2;
        return;
    }

    drafting.value = true;
    draftErrors.value = {};
    draftFailed.value = '';
    loaderLine.value = 0;
    loaderTimer = setInterval(() => (loaderLine.value = (loaderLine.value + 1) % loaderLines.value.length), 2600);

    try {
        const res = await postJson('/worker/resume/builder/draft', answers());

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

        edit.value = toEditable((await res.json()).resume);
        step.value = 4;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch {
        draftFailed.value = t('resumeBuilder.draftFailed');
    } finally {
        drafting.value = false;
        clearInterval(loaderTimer);
    }
};

// ───────────────────────── live preview ─────────────────────────

const resumePayload = () => {
    const p = edit.value!;
    return {
        headline: p.headline,
        summary: p.summary,
        skills: p.skills,
        experience: p.experience.map((j) => ({ ...j, points: lines(j.points) })),
        education: lines(p.education),
        languages: p.languages,
        extras: lines(p.extras),
    };
};

const previewBlob = ref<Blob | null>(null);
const previewUrl = ref<string | null>(null);
const previewing = ref(false);
const previewFailed = ref(false);
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewSeq = 0;

const refreshPreview = async () => {
    if (!edit.value) return;
    const seq = ++previewSeq;
    previewing.value = true;
    previewFailed.value = false;

    try {
        const res = await postJson('/worker/resume/builder/preview', { ...answers(), resume: resumePayload() });
        if (!res.ok) throw new Error(String(res.status));
        const blob = await res.blob();
        // A slower, older render must not replace a newer one.
        if (seq !== previewSeq) return;
        if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
        previewBlob.value = blob;
        previewUrl.value = URL.createObjectURL(blob);
    } catch {
        if (seq === previewSeq) previewFailed.value = true;
    } finally {
        if (seq === previewSeq) previewing.value = false;
    }
};

const schedulePreview = (delay = 900) => {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(refreshPreview, delay);
};

watch(edit, () => step.value === 4 && schedulePreview(), { deep: true });
watch(
    () => [details.template, details.accent, details.show_photo],
    () => step.value === 4 && schedulePreview(150),
);
watch(step, (s) => s === 4 && schedulePreview(50), { immediate: true });

onBeforeUnmount(() => {
    clearTimeout(previewTimer);
    clearInterval(loaderTimer);
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});

// ───────────────────────── save ─────────────────────────

const saving = ref(false);
const saveErrors = computed(() => Object.values((page.props.errors ?? {}) as Record<string, string>));

const save = () => {
    if (!edit.value) return;
    router.post(
        '/worker/resume/builder',
        { ...answers(), resume: resumePayload() },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
        },
    );
};

// ───────────────────────── bits ─────────────────────────

const addJob = () => {
    if (details.work.length < props.limits.jobs) details.work.push(emptyJob());
};

const languageSuggestions = ['Hindi', 'English', 'Urdu', 'Bengali', 'Gujarati', 'Marathi', 'Punjabi', 'Rajasthani', 'Tamil', 'Telugu', 'Kannada', 'Odia', 'Bhojpuri'];

const initials = computed(() =>
    details.name
        .replace(/\(.*?\)/g, '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p[0] ?? '')
        .join('')
        .toUpperCase(),
);

const card = 'rounded-2xl border bg-card p-5 shadow-sm md:p-6';
const heading = 'mb-1 flex items-center gap-2 text-base font-semibold';
const sub = 'mb-5 text-sm text-muted-foreground';
const textarea =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<template>
    <Head :title="$t('resumeBuilder.title')" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Sparkles" :title="$t('resumeBuilder.title')" :description="$t('resumeBuilder.subtitle')" />

        <!-- The resume on record, when the builder made it -->
        <div v-if="resume && resume.source === 'ai'" class="flex flex-wrap items-center gap-3 rounded-2xl border border-emerald-600/25 bg-emerald-600/5 p-4">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-600/10 text-emerald-700 dark:text-emerald-400">
                <BadgeCheck class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ $t('resumeBuilder.savedTitle') }}</p>
                <p class="text-xs text-muted-foreground">{{ $t('resumeBuilder.savedHint', { when: resume.uploaded_ago }) }}</p>
            </div>
            <a href="/worker/resume" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border bg-card px-3 py-2 text-xs font-semibold transition hover:bg-muted">
                <Download class="size-3.5" /> {{ $t('resumeBuilder.download') }}
            </a>
        </div>

        <!-- Stepper -->
        <nav class="rounded-2xl border bg-card p-3 shadow-sm md:p-4">
            <ol class="grid grid-cols-4 gap-2">
                <li v-for="s in steps" :key="s.n">
                    <button
                        type="button"
                        class="group flex w-full items-center justify-center gap-2.5 rounded-xl px-2 py-2 text-left transition hover:bg-muted/60 disabled:cursor-not-allowed disabled:opacity-50 md:justify-start md:px-3"
                        :disabled="s.n === 4 && !edit"
                        @click="go(s.n)"
                    >
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full border text-xs font-bold transition"
                            :class="
                                step === s.n
                                    ? 'border-primary bg-primary text-white'
                                    : step > s.n || (s.n === 4 && edit)
                                      ? 'border-primary/30 bg-primary/10 text-primary'
                                      : 'text-muted-foreground'
                            "
                        >
                            <Check v-if="step > s.n" class="size-4" />
                            <component :is="s.icon" v-else class="size-4" />
                        </span>
                        <span class="hidden min-w-0 md:block">
                            <span class="block text-[11px] font-medium text-muted-foreground">{{ $t('resumeBuilder.stepOf', { n: s.n }) }}</span>
                            <span class="block truncate text-sm font-semibold" :class="step === s.n ? 'text-foreground' : 'text-muted-foreground'">{{ s.label }}</span>
                        </span>
                    </button>
                </li>
            </ol>
            <div class="mt-3 h-1 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-primary transition-all duration-500" :style="{ width: `${(step / 4) * 100}%` }" />
            </div>
            <p class="mt-2 text-center text-xs font-semibold md:hidden">{{ steps[step - 1].label }}</p>
        </nav>

        <!-- ───────── Steps 1–3: the details, with the strength panel beside ───────── -->
        <div v-if="step < 4" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <div class="flex flex-col gap-5">
                <!-- Step 1 -->
                <template v-if="step === 1">
                    <section :class="card">
                        <h2 :class="heading"><UserRound class="size-5 text-primary" /> {{ $t('resumeBuilder.aboutTitle') }}</h2>
                        <p :class="sub">{{ $t('resumeBuilder.aboutHint') }}</p>

                        <!-- Photo -->
                        <div class="mb-6 flex flex-wrap items-center gap-4 rounded-xl border bg-muted/20 p-4">
                            <div class="relative size-16 shrink-0 overflow-hidden rounded-full bg-primary text-xl font-bold text-white">
                                <img v-if="photoUrl" :src="photoUrl" alt="" class="size-full object-cover" />
                                <span v-else class="flex size-full items-center justify-center">{{ initials || '?' }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ $t('resumeBuilder.photo') }}</p>
                                <p class="text-xs text-muted-foreground">{{ photoUrl ? $t('resumeBuilder.photoHint') : $t('resumeBuilder.noPhotoHint') }}</p>
                            </div>
                            <label v-if="photoUrl" class="flex items-center gap-2 text-sm font-medium">
                                <Checkbox :model-value="details.show_photo" @update:model-value="(v: boolean | 'indeterminate') => (details.show_photo = v === true)" />
                                {{ $t('resumeBuilder.showPhoto') }}
                            </label>
                            <Link v-else href="/worker/profile" class="inline-flex items-center gap-1.5 rounded-lg border bg-card px-3 py-2 text-xs font-semibold transition hover:bg-muted">
                                <Camera class="size-3.5" /> {{ $t('resumeBuilder.addPhoto') }}
                            </Link>
                        </div>

                        <div class="mb-5">
                            <Label>{{ $t('resumeBuilder.language') }}</Label>
                            <div class="mt-2 grid max-w-sm grid-cols-2 gap-2">
                                <button
                                    v-for="lang in [
                                        { v: 'en', l: 'English', s: 'Aa' },
                                        { v: 'hi', l: 'हिंदी', s: 'अ' },
                                    ] as const"
                                    :key="lang.v"
                                    type="button"
                                    class="flex items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition"
                                    :class="details.language === lang.v ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'hover:bg-muted/60'"
                                    @click="details.language = lang.v"
                                >
                                    <span class="flex size-8 items-center justify-center rounded-lg bg-muted text-sm font-bold">{{ lang.s }}</span>
                                    <span class="text-sm font-semibold">{{ lang.l }}</span>
                                </button>
                            </div>
                            <p class="mt-1.5 text-xs text-muted-foreground">{{ $t('resumeBuilder.languageHint') }}</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="rb-name">{{ $t('resumeBuilder.name') }} *</Label>
                                <Input id="rb-name" v-model="details.name" maxlength="100" />
                                <InputError :message="stepErrors.name || draftErrors.name" />
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
                </template>

                <!-- Step 2 -->
                <template v-if="step === 2">
                    <section :class="card">
                        <h2 :class="heading"><Wrench class="size-5 text-primary" /> {{ $t('resumeBuilder.yourWork') }}</h2>
                        <p :class="sub">{{ $t('resumeBuilder.yourWorkHint') }}</p>
                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]">
                            <div class="grid gap-2">
                                <Label for="rb-trade">{{ $t('resumeBuilder.trade') }} *</Label>
                                <Input id="rb-trade" v-model="details.trade" maxlength="100" :placeholder="$t('resumeBuilder.tradePlaceholder')" />
                                <InputError :message="stepErrors.trade || draftErrors.trade" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rb-years">{{ $t('resumeBuilder.experienceYears') }}</Label>
                                <Input id="rb-years" v-model.number="details.experience_years" type="number" min="0" max="60" />
                                <InputError :message="draftErrors.experience_years" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="rb-skills">{{ $t('profile.skills') }}</Label>
                                <SkillTagInput id="rb-skills" v-model="details.skills" :suggestions="commonSkills" :placeholder="$t('resumeBuilder.skillsPlaceholder')" />
                                <InputError :message="draftErrors.skills" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="rb-tools">{{ $t('resumeBuilder.tools') }}</Label>
                                <Input id="rb-tools" v-model="details.tools" maxlength="300" :placeholder="$t('resumeBuilder.toolsPlaceholder')" />
                            </div>
                        </div>
                    </section>

                    <section :class="card">
                        <h2 :class="heading"><BriefcaseBusiness class="size-5 text-primary" /> {{ $t('resumeBuilder.workHistory') }}</h2>
                        <p :class="sub">{{ $t('resumeBuilder.workHistoryHint') }}</p>

                        <div class="flex flex-col gap-4">
                            <div v-for="(job, i) in details.work" :key="i" class="relative rounded-xl border bg-muted/20 p-4 pl-5">
                                <span class="absolute top-4 bottom-4 left-0 w-1 rounded-r-full bg-primary/60" />
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-xs font-bold tracking-wide text-muted-foreground uppercase">{{ $t('resumeBuilder.job', { n: i + 1 }) }}</span>
                                    <button type="button" class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-rose-600 transition hover:bg-rose-500/10" @click="details.work.splice(i, 1)">
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
                                        <textarea :id="`rb-details-${i}`" v-model="job.details" rows="3" maxlength="600" :class="textarea" :placeholder="$t('resumeBuilder.jobDetailsPlaceholder')" />
                                        <p class="text-[11px] text-muted-foreground">{{ $t('resumeBuilder.jobDetailsHint') }}</p>
                                    </div>
                                </div>
                            </div>

                            <button
                                v-if="details.work.length < limits.jobs"
                                type="button"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-dashed px-4 py-3 text-sm font-semibold text-muted-foreground transition hover:border-primary/50 hover:bg-primary/5 hover:text-foreground"
                                @click="addJob"
                            >
                                <Plus class="size-4" /> {{ details.work.length ? $t('resumeBuilder.addJob') : $t('resumeBuilder.addFirstJob') }}
                            </button>
                        </div>
                    </section>
                </template>

                <!-- Step 3 -->
                <template v-if="step === 3">
                    <section :class="card">
                        <h2 :class="heading"><GraduationCap class="size-5 text-primary" /> {{ $t('resumeBuilder.educationLanguages') }}</h2>
                        <p :class="sub">{{ $t('resumeBuilder.educationHint') }}</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="rb-edu">{{ $t('resumeBuilder.education') }}</Label>
                                <Input id="rb-edu" v-model="details.education" maxlength="150" :placeholder="$t('resumeBuilder.educationPlaceholder')" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rb-training">{{ $t('resumeBuilder.training') }}</Label>
                                <Input id="rb-training" v-model="details.training" maxlength="300" :placeholder="$t('resumeBuilder.trainingPlaceholder')" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="rb-langs">{{ $t('resumeBuilder.languages') }}</Label>
                                <SkillTagInput id="rb-langs" v-model="details.languages" :suggestions="languageSuggestions" :placeholder="$t('resumeBuilder.languagesPlaceholder')" />
                            </div>
                        </div>
                    </section>

                    <section :class="card">
                        <h2 :class="heading"><Sparkles class="size-5 text-primary" /> {{ $t('resumeBuilder.more') }}</h2>
                        <p :class="sub">{{ $t('resumeBuilder.moreHint') }}</p>
                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="rb-about">{{ $t('resumeBuilder.about') }}</Label>
                                <textarea id="rb-about" v-model="details.about" rows="3" maxlength="600" :class="textarea" :placeholder="$t('resumeBuilder.aboutPlaceholder')" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="rb-achievements">{{ $t('resumeBuilder.achievements') }}</Label>
                                <textarea id="rb-achievements" v-model="details.achievements" rows="2" maxlength="600" :class="textarea" :placeholder="$t('resumeBuilder.achievementsPlaceholder')" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="rb-wage">{{ $t('resumeBuilder.expectedWage') }}</Label>
                                    <Input id="rb-wage" v-model.number="details.expected_wage" type="number" min="0" placeholder="15000" />
                                    <InputError :message="draftErrors.expected_wage" />
                                </div>
                                <label class="flex items-center gap-3 self-end rounded-xl border bg-muted/20 px-4 py-2.5 text-sm font-medium">
                                    <Checkbox :model-value="details.relocate" @update:model-value="(v: boolean | 'indeterminate') => (details.relocate = v === true)" />
                                    {{ $t('resumeBuilder.relocate') }}
                                </label>
                            </div>
                        </div>
                    </section>
                </template>

                <!-- Navigation -->
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <button
                        v-if="step > 1"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-4 py-2.5 text-sm font-semibold transition hover:bg-muted"
                        @click="go((step - 1) as Step)"
                    >
                        <ChevronLeft class="size-4" /> {{ $t('resumeBuilder.back') }}
                    </button>
                    <span v-else />

                    <div class="flex flex-col items-end gap-1.5">
                        <button
                            v-if="step < 3"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 active:scale-95"
                            @click="go((step + 1) as Step)"
                        >
                            {{ $t('resumeBuilder.next') }} <ChevronRight class="size-4" />
                        </button>
                        <button
                            v-else
                            type="button"
                            :disabled="drafting"
                            class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary/25 transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                            @click="writeWithAi"
                        >
                            <Sparkles class="size-4" /> {{ edit ? $t('resumeBuilder.writeAgain') : $t('resumeBuilder.write') }}
                        </button>
                        <p v-if="draftFailed" class="text-sm text-rose-600">{{ draftFailed }}</p>
                        <p v-if="Object.keys(draftErrors).length" class="text-sm text-rose-600">{{ $t('resumeBuilder.fixErrors') }}</p>
                        <button v-if="step === 3 && edit" type="button" class="text-xs font-semibold text-primary hover:underline" @click="go(4)">
                            {{ $t('resumeBuilder.skipToDesign') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Strength + tips -->
            <aside class="flex flex-col gap-4 lg:sticky lg:top-6">
                <div :class="card">
                    <div class="flex items-baseline justify-between">
                        <p class="text-sm font-semibold">{{ $t('resumeBuilder.strength.title') }}</p>
                        <p class="text-2xl font-bold tabular-nums">{{ strength }}%</p>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full transition-all duration-500" :class="strengthColor" :style="{ width: `${strength}%` }" />
                    </div>
                    <p class="mt-1.5 text-xs font-semibold text-muted-foreground">{{ strengthLabel }}</p>
                    <ul class="mt-4 space-y-2">
                        <li v-for="(c, i) in checks" :key="i" class="flex items-start gap-2 text-xs">
                            <span
                                class="mt-px flex size-4 shrink-0 items-center justify-center rounded-full"
                                :class="c.done ? 'bg-emerald-600 text-white' : 'border border-muted-foreground/40'"
                            >
                                <Check v-if="c.done" class="size-3" />
                            </span>
                            <span :class="c.done ? 'text-muted-foreground line-through decoration-muted-foreground/40' : ''">{{ c.tip }}</span>
                        </li>
                    </ul>
                </div>
                <div class="flex gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-4">
                    <Lightbulb class="mt-0.5 size-5 shrink-0 text-primary" />
                    <p class="text-xs leading-relaxed text-muted-foreground">{{ $t('resumeBuilder.aiTip') }}</p>
                </div>
            </aside>
        </div>

        <!-- ───────── Step 4: design, edit, live preview, save ───────── -->
        <div v-else-if="edit" class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="flex flex-col gap-5">
                <!-- Design -->
                <section :class="card">
                    <h2 :class="heading"><Palette class="size-5 text-primary" /> {{ $t('resumeBuilder.design.title') }}</h2>
                    <p :class="sub">{{ $t('resumeBuilder.design.hint') }}</p>

                    <div class="grid grid-cols-3 gap-3">
                        <button
                            v-for="tpl in templates"
                            :key="tpl"
                            type="button"
                            class="group rounded-xl border p-2 text-left transition"
                            :class="details.template === tpl ? 'border-primary ring-2 ring-primary/25' : 'hover:border-foreground/30'"
                            @click="details.template = tpl"
                        >
                            <!-- Miniature of the template -->
                            <div class="aspect-[3/4] overflow-hidden rounded-lg border bg-white">
                                <div v-if="tpl === 'modern'" class="flex h-full">
                                    <div class="flex w-[34%] flex-col items-center gap-1 bg-[#241d19] p-1.5">
                                        <span class="size-5 rounded-full" :style="{ background: details.accent }" />
                                        <span v-for="n in 5" :key="n" class="h-0.5 w-full rounded bg-white/30" />
                                    </div>
                                    <div class="flex flex-1 flex-col gap-1 p-1.5">
                                        <span class="h-1.5 w-3/4 rounded bg-neutral-800" />
                                        <span class="h-1 w-1/2 rounded" :style="{ background: details.accent }" />
                                        <div class="flex gap-0.5"><span v-for="n in 3" :key="n" class="h-2.5 flex-1 rounded-sm" :style="{ background: details.accent, opacity: 0.18 }" /></div>
                                        <span v-for="n in 6" :key="n" class="h-0.5 w-full rounded bg-neutral-300" />
                                    </div>
                                </div>
                                <div v-else-if="tpl === 'classic'" class="flex h-full flex-col items-center gap-1 p-2">
                                    <span class="size-3.5 rounded-full bg-neutral-300" />
                                    <span class="h-1.5 w-2/3 rounded bg-neutral-800" />
                                    <span class="h-1 w-1/2 rounded" :style="{ background: details.accent }" />
                                    <span class="mt-0.5 h-px w-full" :style="{ background: details.accent }" />
                                    <span class="h-px w-full" :style="{ background: details.accent }" />
                                    <span v-for="n in 7" :key="n" class="h-0.5 w-full rounded bg-neutral-300" />
                                </div>
                                <div v-else class="flex h-full flex-col">
                                    <div class="flex items-center justify-between p-1.5" :style="{ background: details.accent }">
                                        <div class="flex w-2/3 flex-col gap-0.5"><span class="h-1.5 w-full rounded bg-white" /><span class="h-1 w-2/3 rounded bg-white/60" /></div>
                                        <span class="size-4 rounded-full bg-white/70" />
                                    </div>
                                    <div class="flex gap-0.5 p-1.5"><span v-for="n in 3" :key="n" class="h-2.5 flex-1 rounded-sm border-t-2" :style="{ borderColor: details.accent, background: '#f1eee9' }" /></div>
                                    <div class="flex flex-col gap-1 px-1.5">
                                        <span v-for="n in 6" :key="n" class="h-0.5 w-full rounded bg-neutral-300" />
                                    </div>
                                </div>
                            </div>
                            <p class="mt-2 flex items-center justify-between text-xs font-semibold">
                                {{ $t(`resumeBuilder.design.${tpl}`) }}
                                <Check v-if="details.template === tpl" class="size-3.5 text-primary" />
                            </p>
                        </button>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-4">
                        <div>
                            <p class="mb-2 text-xs font-semibold text-muted-foreground">{{ $t('resumeBuilder.design.colour') }}</p>
                            <div class="flex gap-2">
                                <button
                                    v-for="c in accents"
                                    :key="c"
                                    type="button"
                                    class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-card transition"
                                    :class="details.accent === c ? 'ring-2 ring-foreground' : 'hover:scale-110'"
                                    :style="{ background: c }"
                                    :aria-label="c"
                                    @click="details.accent = c"
                                >
                                    <Check v-if="details.accent === c" class="size-4 text-white" />
                                </button>
                            </div>
                        </div>
                        <label v-if="photoUrl" class="flex items-center gap-2 text-sm font-medium">
                            <Checkbox :model-value="details.show_photo" @update:model-value="(v: boolean | 'indeterminate') => (details.show_photo = v === true)" />
                            {{ $t('resumeBuilder.showPhoto') }}
                        </label>
                        <span v-if="verified" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-600/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                            <BadgeCheck class="size-3.5" /> {{ $t('resumeBuilder.design.verifiedBadge') }}
                        </span>
                    </div>

                    <!-- Below xl the preview sits under the editor; jump to it. -->
                    <a href="#resume-preview" class="mt-5 flex items-center justify-center gap-2 rounded-xl border bg-muted/30 px-4 py-2.5 text-sm font-semibold transition hover:bg-muted xl:hidden">
                        <FileText class="size-4 text-primary" /> {{ $t('resumeBuilder.live.jump') }}
                    </a>
                </section>

                <!-- Content -->
                <section :class="card">
                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 :class="heading"><FileText class="size-5 text-primary" /> {{ $t('resumeBuilder.content') }}</h2>
                            <p class="text-sm text-muted-foreground">{{ $t('resumeBuilder.previewHint') }}</p>
                        </div>
                        <button
                            type="button"
                            :disabled="drafting"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold transition hover:bg-muted disabled:opacity-50"
                            @click="writeWithAi"
                        >
                            <RefreshCw class="size-3.5" :class="{ 'animate-spin': drafting }" /> {{ $t('resumeBuilder.writeAgain') }}
                        </button>
                    </div>

                    <div class="flex flex-col gap-5">
                        <div class="grid gap-2">
                            <Label>{{ $t('resumeBuilder.headline') }}</Label>
                            <Input v-model="edit.headline" maxlength="150" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('resumeBuilder.summary') }}</Label>
                            <textarea v-model="edit.summary" rows="4" maxlength="1200" :class="textarea" />
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('profile.skills') }}</Label>
                            <SkillTagInput v-model="edit.skills" :suggestions="commonSkills" />
                        </div>

                        <div v-if="edit.experience.length" class="grid gap-3">
                            <Label>{{ $t('resumeBuilder.workHistory') }}</Label>
                            <div v-for="(job, i) in edit.experience" :key="i" class="relative rounded-xl border bg-muted/20 p-4 pl-5">
                                <span class="absolute top-4 bottom-4 left-0 w-1 rounded-r-full bg-primary/60" />
                                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px]">
                                    <Input v-model="job.role" class="font-semibold" maxlength="100" />
                                    <Input v-model="job.period" maxlength="60" :placeholder="$t('resumeBuilder.period')" />
                                </div>
                                <p class="mt-1.5 text-xs text-muted-foreground">{{ [job.employer, job.place].filter(Boolean).join(', ') }}</p>
                                <textarea v-model="job.points" rows="4" :class="[textarea, 'mt-2']" />
                                <p class="text-[11px] text-muted-foreground">{{ $t('resumeBuilder.onePerLine') }}</p>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid content-start gap-2">
                                <Label>{{ $t('resumeBuilder.education') }}</Label>
                                <textarea v-model="edit.education" rows="3" :class="textarea" />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label>{{ $t('resumeBuilder.languages') }}</Label>
                                <SkillTagInput v-model="edit.languages" :suggestions="languageSuggestions" />
                            </div>
                        </div>
                        <div class="grid gap-2">
                            <Label>{{ $t('resumeBuilder.more') }}</Label>
                            <textarea v-model="edit.extras" rows="3" :class="textarea" />
                            <p class="text-[11px] text-muted-foreground">{{ $t('resumeBuilder.onePerLine') }}</p>
                        </div>
                    </div>
                </section>

                <div v-if="saveErrors.length" class="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <p v-for="(e, i) in saveErrors" :key="i">{{ e }}</p>
                </div>
                <p v-if="draftFailed" class="text-sm text-rose-600">{{ draftFailed }}</p>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-4 py-2.5 text-sm font-semibold transition hover:bg-muted" @click="go(3)">
                        <ChevronLeft class="size-4" /> {{ $t('resumeBuilder.editDetails') }}
                    </button>
                    <button
                        type="button"
                        :disabled="saving"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary/25 transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                        @click="save"
                    >
                        <LoaderCircle v-if="saving" class="size-4 animate-spin" />
                        <Check v-else class="size-4" />
                        {{ saving ? $t('resumeBuilder.saving') : $t('resumeBuilder.save') }}
                    </button>
                </div>
            </div>

            <!-- Live PDF preview -->
            <aside id="resume-preview" class="scroll-mt-4 xl:sticky xl:top-6">
                <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b px-4 py-3">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="relative flex size-2.5 shrink-0">
                                <span v-if="previewing" class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-75" />
                                <span class="relative inline-flex size-2.5 rounded-full" :class="previewFailed ? 'bg-rose-500' : previewing ? 'bg-amber-500' : 'bg-emerald-500'" />
                            </span>
                            <p class="text-sm font-semibold">{{ $t('resumeBuilder.live.title') }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ previewFailed ? $t('resumeBuilder.live.failed') : previewing ? $t('resumeBuilder.live.updating') : $t('resumeBuilder.live.ready') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" class="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground" :title="$t('resumeBuilder.live.refresh')" @click="refreshPreview">
                                <RefreshCw class="size-4" :class="{ 'animate-spin': previewing }" />
                            </button>
                            <a
                                v-if="previewUrl"
                                :href="previewUrl"
                                target="_blank"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted"
                            >
                                <ExternalLink class="size-3.5" /> {{ $t('resumeBuilder.live.open') }}
                            </a>
                        </div>
                    </div>
                    <div class="relative max-h-[calc(100vh-9rem)] overflow-y-auto bg-muted/40 p-3">
                        <PdfPreview :src="previewBlob" :class="{ 'opacity-60 transition-opacity': previewing }" />
                        <div v-if="!previewBlob" class="flex aspect-[210/297] w-full items-center justify-center rounded-lg border bg-white">
                            <LoaderCircle class="size-6 animate-spin text-muted-foreground" />
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- AI writing overlay -->
        <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="drafting" class="fixed inset-0 z-50 flex items-center justify-center bg-background/70 p-6 backdrop-blur-sm">
                <div class="w-full max-w-sm rounded-2xl border bg-card p-6 text-center shadow-xl">
                    <span class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                        <Sparkles class="size-7 animate-pulse" />
                    </span>
                    <p class="mt-4 text-base font-semibold">{{ $t('resumeBuilder.writing') }}</p>
                    <p class="mt-1 h-5 text-sm text-muted-foreground">{{ loaderLines[loaderLine] }}</p>
                    <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-muted">
                        <div class="resume-progress h-full w-1/3 rounded-full bg-primary" />
                    </div>
                    <p class="mt-4 text-[11px] text-muted-foreground">{{ $t('resumeBuilder.loader.wait') }}</p>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.resume-progress {
    animation: resume-progress 1.6s ease-in-out infinite;
}

@keyframes resume-progress {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(300%);
    }
}
</style>
