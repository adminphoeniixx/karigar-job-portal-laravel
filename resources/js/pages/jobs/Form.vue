<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BadgeCheck,
    Bot,
    BriefcaseBusiness,
    Building2,
    CalendarClock,
    Check,
    ClipboardCheck,
    Gift,
    Handshake,
    House,
    IndianRupee,
    LifeBuoy,
    ListChecks,
    Lock,
    MapPin,
    Pencil,
    Phone,
    RotateCcw,
    Settings2,
    ShieldAlert,
    Shuffle,
    Sparkles,
    Sun,
    TriangleAlert,
    UserRoundCheck,
    Video,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import JobMap from '@/components/JobMap.vue';
import JobTitleCombobox, { type JobProfile } from '@/components/JobTitleCombobox.vue';
import ChoiceChips from '@/components/job-form/ChoiceChips.vue';
import DayPicker from '@/components/job-form/DayPicker.vue';
import MatchingKarigarsPanel from '@/components/job-form/MatchingKarigarsPanel.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { citiesFor, indianStates } from '@/data/indianLocations';
import {
    ageRange,
    ASSETS,
    assetKey,
    DEFAULT_WORK_DAYS,
    daysLabel,
    EDUCATION_LEVELS,
    educationKey,
    ENGLISH_LEVELS,
    englishKey,
    type ExperienceLevel,
    experienceLevelOf,
    FEE_PURPOSES,
    feePurposeKey,
    hoursLabel,
    jobTypeKey,
    urgencyKey,
} from '@/lib/jobDetails';
import { plainNumber } from '@/lib/utils';

interface Job {
    id: number;
    title: string;
    description: string;
    category: string | null;
    skills: string[] | null;
    wage_min: string | null;
    wage_max: string | null;
    wage_type: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    work_mode: 'on_site' | 'remote' | 'both';
    interview_mode: 'on_site' | 'remote' | 'both';
    latitude: string | null;
    longitude: string | null;
    vacancies: number;
    experience_min: number | null;
    experience_max: number | null;
    status: string;
    published_at?: string | null;
    expires_at: string | null;
    contact_mode: 'apply' | 'call' | 'both';
    contact_phone: string | null;
    contact_name: string | null;
    contact_designation: string | null;
    shift: 'day' | 'night' | 'rotational' | 'flexible' | null;
    shift_start: string | null;
    shift_end: string | null;
    shift_timings: { start: string; end: string }[] | null;
    work_hours: number | null;
    work_hours_period: 'day' | 'week' | null;
    core_start: string | null;
    core_end: string | null;
    gender: 'male' | 'female' | 'both' | null;
    perks: string[] | null;
    requires_worker_fee: boolean;
    worker_fee_amount: string | null;
    worker_fee_purpose?: string | null;
    ai_shortlist_enabled: boolean;
    ai_call_enabled: boolean;
    // The WorkIndia-style details (2026-11-19).
    job_type?: 'full_time' | 'part_time' | null;
    education?: string | null;
    english?: string | null;
    age_min?: number | null;
    age_max?: number | null;
    languages?: string[] | null;
    assets?: string[] | null;
    reach?: 'nearby' | 'city' | null;
    relocation_allowed?: boolean | null;
    incentive_max?: string | null;
    incentive_type?: string | null;
    work_days?: string[] | null;
    interview_days?: string[] | null;
    interview_start?: string | null;
    interview_end?: string | null;
    interview_address?: string | null;
    hiring_urgency?: 'immediate' | 'can_wait' | null;
    other_requirements?: string | null;
}

type ReqField = 'experience_level' | 'education' | 'english' | 'gender' | 'reach';

interface RequirementDefaults {
    values: Record<ReqField, string>;
    jobs: number;
    // The requirements the category's jobs answered; the rest are the usual values.
    from_jobs: ReqField[];
}

const props = defineProps<{
    job: Job | null;
    defaultPhone: string | null;
    freePostAvailable?: boolean;
    // Set while the employer's business is not verified yet: the job can only be saved as a draft.
    verificationBlock?: string | null;
    // Skills to suggest per category, and the perks to offer (the usual ones
    // plus this employer's own from earlier jobs).
    categorySkills: Record<string, string[]>;
    // Job titles for the title dropdown, each with its category.
    jobProfiles: JobProfile[];
    perkOptions: string[];
    // Which AI switches the admin has on platform-wide; the rest show disabled.
    aiOptions: {
        shortlist_available: boolean;
        call_available: boolean;
        // AI calls are paid for with credits; `can_enable` false = recharge first.
        credits?: { enabled: boolean; balance: number; min_for_job: number; can_enable: boolean };
    };
    // The lists and limits for the 2026-11-19 details.
    detailOptions: { spoken_languages: string[]; age_min: number; age_max: number; nearby_km: number };
    // Per category, the requirements most of its jobs ask ('' = the usual ones).
    requirementDefaults: Record<string, RequirementDefaults>;
    // "Need help? Call …"
    support: { phone: string; hours: string };
}>();

const isEdit = props.job !== null;

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }] },
});

const page = usePage();
const { t } = useI18n();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

// "Other (not in the list)": the job goes out with no category. Karigars then
// find it only by searching, since feeds and alerts follow the category. A
// live job saved that way shows Other again when edited.
const OTHER = '__other__';

const form = useForm({
    title: props.job?.title ?? '',
    description: props.job?.description ?? '',
    category: props.job ? (props.job.category ?? (props.job.published_at ? OTHER : '')) : '',
    skills: props.job?.skills ?? [],
    wage_min: plainNumber(props.job?.wage_min),
    wage_max: plainNumber(props.job?.wage_max),
    // Wages are monthly only (App\Support\Wage).
    wage_type: 'monthly',
    address: props.job?.address ?? '',
    city: props.job?.city ?? '',
    state: props.job?.state ?? '',
    work_mode: props.job?.work_mode ?? 'on_site',
    interview_mode: props.job?.interview_mode ?? 'on_site',
    latitude: props.job?.latitude ?? '',
    longitude: props.job?.longitude ?? '',
    vacancies: props.job?.vacancies ?? 1,
    experience_min: props.job?.experience_min ?? ('' as number | ''),
    experience_max: props.job?.experience_max ?? ('' as number | ''),
    status: props.job?.status ?? 'active',
    expires_at: props.job?.expires_at?.slice(0, 10) ?? '',
    contact_mode: props.job?.contact_mode ?? 'apply',
    contact_phone: props.job?.contact_phone ?? props.defaultPhone ?? '',
    contact_name: props.job?.contact_name ?? '',
    contact_designation: props.job?.contact_designation ?? '',
    shift: props.job?.shift ?? '',
    shift_start: props.job?.shift_start ?? '',
    shift_end: props.job?.shift_end ?? '',
    shift_timings: props.job?.shift_timings?.length ? props.job.shift_timings.map((t) => ({ ...t })) : [{ start: '', end: '' }],
    work_hours: props.job?.work_hours ?? '',
    work_hours_period: props.job?.work_hours_period ?? 'day',
    core_start: props.job?.core_start ?? '',
    core_end: props.job?.core_end ?? '',
    gender: (props.job?.gender ?? '') as string,
    perks: props.job?.perks ?? [],
    requires_worker_fee: props.job?.requires_worker_fee ?? false,
    worker_fee_amount: props.job?.worker_fee_amount ?? '',
    worker_fee_purpose: props.job?.worker_fee_purpose ?? '',
    ai_shortlist_enabled: props.job?.ai_shortlist_enabled ?? true,
    // A new job starts with AI calls on only when there are credits to pay for them.
    ai_call_enabled: props.job?.ai_call_enabled ?? (props.aiOptions.credits?.can_enable ?? true),
    // The WorkIndia-style details. A new job starts on Monday–Saturday with
    // interviews 11 AM – 4 PM; an older job keeps whatever it never said.
    job_type: (props.job?.job_type ?? 'full_time') as string,
    education: props.job?.education ?? '',
    english: props.job?.english ?? '',
    age_min: props.job?.age_min ?? ('' as number | ''),
    age_max: props.job?.age_max ?? ('' as number | ''),
    languages: props.job?.languages ?? [],
    assets: props.job?.assets ?? [],
    reach: (props.job?.reach ?? '') as string,
    relocation_allowed: props.job?.relocation_allowed ?? false,
    incentive_max: plainNumber(props.job?.incentive_max),
    incentive_type: props.job?.incentive_type ?? '',
    work_days: props.job?.work_days?.length ? [...props.job.work_days] : isEdit ? [] : [...DEFAULT_WORK_DAYS],
    interview_days: props.job?.interview_days?.length ? [...props.job.interview_days] : isEdit ? [] : [...DEFAULT_WORK_DAYS],
    interview_start: props.job?.interview_start ?? (isEdit ? '' : '11:00'),
    interview_end: props.job?.interview_end ?? (isEdit ? '' : '16:00'),
    interview_address: props.job?.interview_address ?? '',
    hiring_urgency: (props.job?.hiring_urgency ?? '') as string,
    other_requirements: props.job?.other_requirements ?? '',
});

type JobFormData = ReturnType<typeof form.data>;
type JobFormKey = keyof JobFormData;

// ── Steps ───────────────────────────────────────────────────────────
// The form in four steps, as WorkIndia's: the basics, who the job is for,
// timings and interview, then a review before posting. An edit can open any
// step; a new job opens each in turn.
const STEPS = ['basics', 'requirements', 'details', 'review'] as const;
type Step = (typeof STEPS)[number];

const step = ref<Step>('basics');
const stepIndex = computed(() => STEPS.indexOf(step.value));
const reached = ref(isEdit ? STEPS.length - 1 : 0);
const formTop = ref<HTMLElement | null>(null);

const goTo = (target: Step) => {
    step.value = target;
    reached.value = Math.max(reached.value, STEPS.indexOf(target));
    nextTick(() => formTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};

const canOpen = (target: Step) => STEPS.indexOf(target) <= reached.value;

// Which step holds each field: a server error opens the step it belongs to.
const STEP_FIELDS: Record<Step, JobFormKey[]> = {
    basics: ['title', 'category', 'job_type', 'vacancies', 'state', 'city', 'address', 'latitude', 'longitude', 'wage_min', 'wage_max', 'wage_type', 'incentive_max', 'incentive_type', 'other_requirements'],
    requirements: ['experience_min', 'experience_max', 'education', 'english', 'gender', 'reach', 'relocation_allowed', 'skills', 'age_min', 'age_max', 'languages', 'assets'],
    details: [
        'shift',
        'shift_start',
        'shift_end',
        'shift_timings',
        'work_hours',
        'work_hours_period',
        'core_start',
        'core_end',
        'work_days',
        'work_mode',
        'interview_mode',
        'interview_days',
        'interview_start',
        'interview_end',
        'interview_address',
        'perks',
        'requires_worker_fee',
        'worker_fee_amount',
        'worker_fee_purpose',
        'description',
    ],
    review: ['contact_mode', 'contact_phone', 'contact_name', 'contact_designation', 'hiring_urgency', 'ai_shortlist_enabled', 'ai_call_enabled', 'status', 'expires_at'],
};

const stepOfField = (field: string): Step =>
    STEPS.find((s) => STEP_FIELDS[s].some((f) => field === f || field.startsWith(`${String(f)}.`))) ?? 'review';

const stepHasErrors = (s: Step) => Object.keys(form.errors).some((field) => stepOfField(field) === s);

// ── Shifts ──────────────────────────────────────────────────────────
// A day or night shift starts with the usual hours filled in; a rotational
// job lists each shift; a flexible one gives its total hours and, optionally,
// a core window that must fit inside them.
const SHIFT_DEFAULTS: Record<string, { start: string; end: string }> = {
    day: { start: '09:00', end: '18:00' },
    night: { start: '21:00', end: '06:00' },
};
const MAX_SHIFT_TIMINGS = 6;

watch(
    () => form.shift,
    (shift, previous) => {
        const defaults = SHIFT_DEFAULTS[shift as string];
        const wasDefault = previous && SHIFT_DEFAULTS[previous as string];
        const untouched = !form.shift_start || (wasDefault && form.shift_start === wasDefault.start && form.shift_end === wasDefault.end);

        if (defaults && untouched) {
            form.shift_start = defaults.start;
            form.shift_end = defaults.end;
        }
    },
);

const showCoreHours = ref(!!(props.job?.core_start && props.job?.core_end));

const addShiftTiming = () => {
    if (form.shift_timings.length < MAX_SHIFT_TIMINGS) {
        form.shift_timings = [...form.shift_timings, { start: '', end: '' }];
    }
};

const removeShiftTiming = (index: number) => {
    form.shift_timings = form.shift_timings.filter((_, i) => i !== index);
    if (!form.shift_timings.length) {
        form.shift_timings = [{ start: '', end: '' }];
    }
};

// Only the hours that belong to the shift picked go to the server.
const scheduleFields = (data: Record<string, unknown>) => {
    const shift = data.shift;

    if (shift === 'rotational') {
        const timings = form.shift_timings.filter((t) => t.start || t.end);
        Object.assign(data, { shift_start: '', shift_end: '', shift_timings: timings.length ? timings : null });
    } else {
        data.shift_timings = null;
    }

    if (shift === 'flexible') {
        Object.assign(data, { shift_start: '', shift_end: '' });
        if (!showCoreHours.value) {
            Object.assign(data, { core_start: '', core_end: '' });
        }
    } else {
        Object.assign(data, { work_hours: '', work_hours_period: '', core_start: '', core_end: '' });
    }

    return data;
};

const errorAt = (field: string) => (form.errors as Record<string, string | undefined>)[field];

// ── Title → category and skills ─────────────────────────────────────
// The job title suggests its category and skills (a picked job profile or a
// title of the employer's own). They fill only what the employer has not
// chosen themselves, and stay editable: a skill tag comes off with its ✕.
interface TitleSuggestion {
    category: string;
    job_profile: string | null;
    skills: string[];
}
const titleSuggestion = ref<TitleSuggestion | null>(null);
const autoCategory = ref<string | null>(null);
const autoSkills = ref<string[] | null>(null);
let titleTimer: ReturnType<typeof setTimeout> | undefined;

const sameList = (a: string[], b: string[]) => a.length === b.length && a.every((x, i) => x === b[i]);

// A job that has been live keeps its title; a new role is a new post.
const titleLocked = isEdit && !!props.job?.published_at;

const applyTitleSuggestion = (s: TitleSuggestion) => {
    if (!form.category || form.category === autoCategory.value) {
        form.category = s.category;
        autoCategory.value = s.category;
    }

    if (form.category === s.category && (!form.skills.length || (autoSkills.value && sameList(form.skills, autoSkills.value)))) {
        const top = s.skills.slice(0, 3);
        form.skills = [...top];
        autoSkills.value = top;
    }
};

watch(
    () => form.title,
    (title) => {
        clearTimeout(titleTimer);
        const typed = title.trim();
        if (titleLocked || typed.length < 3) {
            titleSuggestion.value = null;
            return;
        }

        titleTimer = setTimeout(async () => {
            try {
                const res = await fetch(`/employer/jobs/suggest-category?${new URLSearchParams({ title: typed })}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok || form.title.trim() !== typed) return;

                const data = await res.json();
                titleSuggestion.value = data.suggestion ?? null;
                if (titleSuggestion.value) {
                    applyTitleSuggestion(titleSuggestion.value);
                } else if (form.category && form.category === autoCategory.value) {
                    // An earlier title filled the category; this one fits none, so the
                    // employer picks it (or Other) rather than keep a stale guess.
                    form.category = '';
                    autoCategory.value = null;
                    if (autoSkills.value && sameList(form.skills, autoSkills.value)) {
                        form.skills = [];
                        autoSkills.value = null;
                    }
                }
            } catch {
                // A suggestion is a convenience; posting never waits on it.
            }
        }, 450);
    },
);

// A title picked from the list names its category, so that fills at once
// (unless the employer chose one themselves). A title of their own waits for
// suggest-category above; if that finds nothing, the category box is next.
const categorySelect = ref<HTMLSelectElement | null>(null);

const onTitlePick = (profile: JobProfile | null) => {
    if (profile) {
        if (!form.category || form.category === autoCategory.value) {
            form.category = profile.category;
            autoCategory.value = profile.category;
        }
        return;
    }

    if (!form.category) categorySelect.value?.focus();
};

// The line under the category box.
const categoryHint = computed<'suggested' | 'other' | 'pick' | null>(() => {
    if (form.category === OTHER) return 'other';
    if (autoCategory.value && form.category === autoCategory.value) return 'suggested';
    if (!form.category && form.title.trim().length >= 3) return 'pick';
    return null;
});

watch(
    () => form.category,
    () => form.clearErrors('category'),
);

// The chosen category's skills, ordered for the title when it suggested this
// category; the employer can still type any other.
const skillSuggestions = computed(() =>
    titleSuggestion.value?.category === form.category && titleSuggestion.value.skills.length ? titleSuggestion.value.skills : (props.categorySkills[form.category] ?? []),
);

// ── Who the job is for ──────────────────────────────────────────────
// Experience as WorkIndia asks it: any, freshers only, or experienced with
// the years. It is stored as the experience range.
const experienceLevel = ref<ExperienceLevel>(experienceLevelOf(form.experience_min, form.experience_max));

watch(experienceLevel, (level) => {
    if (level === 'any') {
        form.experience_min = '';
        form.experience_max = '';
    } else if (level === 'fresher') {
        form.experience_min = 0;
        form.experience_max = 0;
    } else if (form.experience_min === '' || (form.experience_min === 0 && form.experience_max === 0)) {
        form.experience_min = 1;
        form.experience_max = '';
    }
});

// Once a category is picked, a new job takes the requirements most of that
// category's jobs ask (the usual ones until there are enough), for whatever
// the employer has not chosen themselves.
const touched = reactive<Record<ReqField, boolean>>({
    experience_level: isEdit,
    education: isEdit,
    english: isEdit,
    gender: isEdit,
    reach: isEdit,
});

const defaults = computed<RequirementDefaults | null>(
    () => props.requirementDefaults[form.category && form.category !== OTHER ? form.category : ''] ?? props.requirementDefaults[''] ?? null,
);

const applyDefaults = () => {
    const values = defaults.value?.values;
    if (!values || isEdit) return;

    if (!touched.experience_level) experienceLevel.value = values.experience_level as ExperienceLevel;
    if (!touched.education) form.education = values.education;
    if (!touched.english) form.english = values.english;
    if (!touched.gender) form.gender = values.gender;
    if (!touched.reach) form.reach = values.reach;
};

applyDefaults();
watch(() => form.category, applyDefaults);

const pickRequirement = (field: ReqField, value: string) => {
    touched[field] = true;
    if (field === 'experience_level') experienceLevel.value = value as ExperienceLevel;
    else form[field] = value;
};

// "Most chosen" on the option the category's jobs pick most; "Usual" where
// they did not say enough and the usual value stands.
const suggested = computed(() => defaults.value?.values ?? null);
const tagFor = (field: ReqField) => (defaults.value?.from_jobs?.includes(field) ? t('jobForm.suggestedTag') : t('jobForm.usualTag'));

const autoFillNote = computed(() =>
    defaults.value?.from_jobs?.length && form.category && form.category !== OTHER
        ? t('jobForm.requirementsAutoFilled', { count: defaults.value.jobs, category: form.category })
        : t('jobForm.requirementsUsual'),
);

const experienceOptions = computed(() => [
    { value: 'any', label: t('jobForm.expAny') },
    { value: 'fresher', label: t('jobForm.expFresher') },
    { value: 'experienced', label: t('jobForm.expExperienced') },
]);
const educationOptions = computed(() => EDUCATION_LEVELS.map((level) => ({ value: level as string, label: t(educationKey(level)) })));
const englishOptions = computed(() => ENGLISH_LEVELS.map((level) => ({ value: level as string, label: t(englishKey(level)) })));
const genderOptions = computed(() => [
    { value: 'both', label: t('jobForm.genderBoth') },
    { value: 'male', label: t('jobForm.genderMale') },
    { value: 'female', label: t('jobForm.genderFemale') },
]);
const reachOptions = computed(() => [
    { value: 'nearby', label: t('jobForm.reachNearby', { km: props.detailOptions.nearby_km }), desc: t('jobForm.reachNearbyDesc') },
    { value: 'city', label: t('jobForm.reachCity', { city: form.city || t('jobForm.theCity') }), desc: t('jobForm.reachCityDesc') },
]);
const relocationOptions = computed(() => [
    { value: true, label: t('jobForm.relocationYes') },
    { value: false, label: t('jobForm.relocationNo') },
]);
const jobTypeOptions = computed(() => [
    { value: 'full_time', label: t(jobTypeKey('full_time')) },
    { value: 'part_time', label: t(jobTypeKey('part_time')) },
]);
const urgencyOptions = computed(() => [
    { value: 'immediate', label: t('jobForm.urgencyImmediate'), desc: t('jobForm.urgencyImmediateDesc') },
    { value: 'can_wait', label: t('jobForm.urgencyCanWait'), desc: t('jobForm.urgencyCanWaitDesc') },
]);
const yesNo = computed(() => [
    { value: true, label: t('jobForm.yes') },
    { value: false, label: t('jobForm.no') },
]);

// Age, languages and documents sit behind "More requirements", opened by
// tapping each; closing one clears it.
const extras = reactive({
    age: !!(props.job?.age_min || props.job?.age_max),
    languages: !!props.job?.languages?.length,
    assets: !!props.job?.assets?.length,
});

const closeExtra = (extra: keyof typeof extras) => {
    extras[extra] = false;
    if (extra === 'age') {
        form.age_min = '';
        form.age_max = '';
    } else {
        form[extra] = [];
    }
};

const toggleIn = (field: 'languages' | 'assets', value: string) => {
    form[field] = form[field].includes(value) ? form[field].filter((v) => v !== value) : [...form[field], value];
};

// ── Pay ─────────────────────────────────────────────────────────────
// A bonus or incentive on top of the salary: up to how much, and what for.
const hasIncentive = ref(!!props.job?.incentive_max);
const INCENTIVE_TYPES = ['Per-piece rate', 'Overtime', 'Performance bonus', 'Festival bonus', 'Commission'];

// Perk chips: the offered ones, then any the employer typed on this job.
const perkChips = computed(() => {
    const seen = new Set(props.perkOptions.map((p) => p.toLowerCase()));
    return [...props.perkOptions, ...form.perks.filter((p) => !seen.has(p.toLowerCase()))];
});

const togglePerk = (perk: string) => {
    form.perks = form.perks.includes(perk) ? form.perks.filter((x) => x !== perk) : [...form.perks, perk];
};

const newPerk = ref('');
const addPerk = () => {
    const perk = newPerk.value.trim();
    if (perk && !form.perks.some((p) => p.toLowerCase() === perk.toLowerCase())) {
        form.perks = [...form.perks, perk];
    }
    newPerk.value = '';
};

// ── AI switches ─────────────────────────────────────────────────────
// Switching AI calls on needs credits, unless the job already had them on.
const credits = computed(() => props.aiOptions.credits);
const needsRecharge = computed(() => !!credits.value?.enabled && !credits.value.can_enable && !(props.job?.ai_call_enabled && props.job?.published_at));

// A switch the admin has off stays visible but disabled, with the reason.
const aiSwitches = computed(() => [
    {
        key: 'ai_shortlist_enabled' as const,
        title: 'jobForm.aiShortlist',
        desc: 'jobForm.aiShortlistDesc',
        available: props.aiOptions.shortlist_available,
        note: props.aiOptions.shortlist_available ? null : 'jobForm.aiOffByAdmin',
    },
    {
        key: 'ai_call_enabled' as const,
        title: 'jobForm.aiCall',
        desc: 'jobForm.aiCallDesc',
        available: props.aiOptions.call_available && form.ai_shortlist_enabled && (!needsRecharge.value || form.ai_call_enabled),
        note: !props.aiOptions.call_available ? 'jobForm.aiOffByAdmin' : !form.ai_shortlist_enabled ? 'jobForm.aiCallNeedsShortlist' : null,
    },
]);

const contactModes = [
    { value: 'apply', label: 'jobForm.applyThroughApp', desc: 'jobForm.applyThroughAppDesc' },
    { value: 'call', label: 'jobForm.directCall', desc: 'jobForm.directCallDesc' },
    { value: 'both', label: 'jobForm.both', desc: 'jobForm.bothDesc' },
] as const;

// Who picks up: a tap fills the usual roles.
const DESIGNATIONS = ['Owner', 'Manager', 'Supervisor', 'HR'];

// Where the work is done, and how the interview is held. A new job that
// turns remote takes a remote interview too, until the employer picks one.
const workModes = [
    { value: 'on_site', label: 'jobs.workOnSite', desc: 'jobForm.workOnSiteDesc', icon: Building2 },
    { value: 'remote', label: 'jobs.workRemote', desc: 'jobForm.workRemoteDesc', icon: House },
    { value: 'both', label: 'jobs.workBoth', desc: 'jobForm.workBothDesc', icon: Shuffle },
] as const;

const interviewModes = [
    { value: 'on_site', label: 'jobs.interviewOnSite', desc: 'jobForm.interviewOnSiteDesc', icon: Handshake },
    { value: 'remote', label: 'jobs.interviewRemote', desc: 'jobForm.interviewRemoteDesc', icon: Video },
    { value: 'both', label: 'jobs.interviewBoth', desc: 'jobForm.interviewBothDesc', icon: Shuffle },
] as const;

const interviewTouched = ref(isEdit);
watch(
    () => form.work_mode,
    (mode) => {
        if (!interviewTouched.value) form.interview_mode = mode === 'remote' ? 'remote' : 'on_site';
    },
);

// The interview is at the job address unless the employer gives another.
const interviewAtJobAddress = ref(!props.job?.interview_address);

const cities = computed(() => citiesFor(form.state));
watch(
    () => form.state,
    () => {
        if (form.city && !cities.value.includes(form.city)) form.city = '';
    },
);

// ── Map picker ──────────────────────────────────────────────────────
const mapLat = ref<number | null>(form.latitude ? Number(form.latitude) : null);
const mapLng = ref<number | null>(form.longitude ? Number(form.longitude) : null);
const locating = ref(false);

const setPoint = (lat: number, lng: number) => {
    mapLat.value = lat;
    mapLng.value = lng;
    form.latitude = String(lat);
    form.longitude = String(lng);
};

// Move the pin to whatever the employer has told us so far, via OpenStreetMap's
// free geocoder. A typed address is the precise answer; city + state alone only
// ever lands on the city centre, so it is the fallback. Either way the employer
// can still drag the pin.
let geoTimer: ReturnType<typeof setTimeout> | undefined;
const geocode = () => {
    const { address, city, state } = form;
    if (!city || !state) return;

    clearTimeout(geoTimer);
    geoTimer = setTimeout(async () => {
        locating.value = true;
        try {
            // A free-text `q` handles a street address; the structured form is
            // more reliable when we only have the city.
            const params: Record<string, string> = address.trim()
                ? { format: 'json', limit: '1', q: [address, city, state, 'India'].join(', ') }
                : { format: 'json', limit: '1', country: 'India', state, city };

            const res = await fetch(`https://nominatim.openstreetmap.org/search?${new URLSearchParams(params)}`, {
                headers: { Accept: 'application/json' },
            });
            const hits = await res.json();
            if (hits[0]) setPoint(Number(hits[0].lat), Number(hits[0].lon));
        } catch {
            // Geocoding is best-effort — the employer can always drop the pin manually.
        } finally {
            locating.value = false;
        }
    }, 700);
};

watch([() => form.city, () => form.state], geocode);
// Typing an address re-locates the pin once the line looks complete.
watch(
    () => form.address,
    (val) => {
        if (val.trim().length === 0 || val.trim().length >= 4) geocode();
    },
);

// ── AI description drafts ───────────────────────────────────────────
// The employer types a title, then lands in the description box — that is the
// moment to offer drafts, so the fetch fires on focus rather than on a click
// they have to discover. One fetch per title; the server caches the rest.
const suggestions = ref<string[]>([]);
const suggesting = ref(false);
const suggestError = ref(false);
const suggestedFor = ref('');
// Drafts in English or Hindi (Devanagari).
const aiLanguage = ref<'en' | 'hi'>('en');

const canSuggest = computed(() => form.title.trim().length >= 3);

const fetchSuggestions = async () => {
    const title = form.title.trim();
    const key = `${aiLanguage.value}:${title}`;
    if (!canSuggest.value || suggesting.value || suggestedFor.value === key) return;

    suggesting.value = true;
    suggestError.value = false;
    try {
        const params = new URLSearchParams({ title, language: aiLanguage.value });
        if (form.category && form.category !== OTHER) params.set('category', form.category);
        if (form.city) params.set('city', form.city);
        if (form.state) params.set('state', form.state);
        form.skills.forEach((s) => params.append('skills[]', s));

        const res = await fetch(`/employer/jobs/suggest-description?${params}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(String(res.status));

        const data = await res.json();
        suggestions.value = data.suggestions ?? [];
        suggestedFor.value = key;
    } catch {
        // Drafting is a convenience — a failure must never block posting.
        suggestError.value = true;
    } finally {
        suggesting.value = false;
    }
};

// Only volunteer drafts into an empty box; never over an employer's own words.
const onDescriptionFocus = () => {
    if (!form.description.trim()) fetchSuggestions();
};

const setAiLanguage = (language: 'en' | 'hi') => {
    aiLanguage.value = language;
    fetchSuggestions();
};

const useSuggestion = (text: string) => {
    form.description = text;
    suggestions.value = [];
};

const money = (n: unknown) => `₹${Number(n).toLocaleString('en-IN')}`;

// ── Unfinished job, kept in the browser ─────────────────────────────
// As WorkIndia's "Restore your … job": a new job being typed is kept in this
// browser, and offered back next time until it is posted or set aside.
const userId = (page.props.auth as { user?: { id: number } | null } | undefined)?.user?.id ?? 'me';
const DRAFT_KEY = `karigar.jobDraft.${userId}`;
const DRAFT_DAYS = 7;

interface SavedDraft {
    at: number;
    step: Step;
    data: Partial<JobFormData>;
    hasIncentive?: boolean;
}

const savedDraft = ref<SavedDraft | null>(null);
// Off while an earlier draft is on offer, so typing does not overwrite it.
let autosave = false;
let saveTimer: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    if (isEdit) return;
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        const parsed = raw ? (JSON.parse(raw) as SavedDraft) : null;
        if (parsed?.data?.title && Date.now() - parsed.at < DRAFT_DAYS * 864e5) {
            savedDraft.value = parsed;
        } else if (raw) {
            localStorage.removeItem(DRAFT_KEY);
        }
    } catch {
        // Storage blocked: nothing to offer, nothing kept.
    }
    autosave = savedDraft.value === null;
});

watch(
    () => form.data(),
    (data) => {
        if (isEdit || !autosave) return;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            try {
                if (String(data.title).trim().length >= 2) {
                    localStorage.setItem(DRAFT_KEY, JSON.stringify({ at: Date.now(), step: step.value, data, hasIncentive: hasIncentive.value }));
                }
            } catch {
                // Storage full or blocked: the form still works.
            }
        }, 800);
    },
    { deep: true },
);

const forgetDraft = () => {
    try {
        localStorage.removeItem(DRAFT_KEY);
    } catch {
        // Nothing kept to forget.
    }
};

const restoreDraft = () => {
    const draft = savedDraft.value;
    if (!draft) return;

    // Keep the restored answers: the category's suggestions must not replace them.
    (Object.keys(touched) as ReqField[]).forEach((field) => (touched[field] = true));

    const fields = form.data();
    for (const [key, value] of Object.entries(draft.data)) {
        if (key in fields && key !== 'status') (form as unknown as Record<string, unknown>)[key] = value;
    }

    experienceLevel.value = experienceLevelOf(form.experience_min, form.experience_max);
    hasIncentive.value = draft.hasIncentive ?? !!form.incentive_max;
    interviewAtJobAddress.value = !form.interview_address;
    extras.age = form.age_min !== '' || form.age_max !== '';
    extras.languages = form.languages.length > 0;
    extras.assets = form.assets.length > 0;
    mapLat.value = form.latitude ? Number(form.latitude) : null;
    mapLng.value = form.longitude ? Number(form.longitude) : null;

    savedDraft.value = null;
    autosave = true;
    reached.value = STEPS.indexOf(draft.step);
    goTo(draft.step);
};

const dismissDraft = () => {
    forgetDraft();
    savedDraft.value = null;
    autosave = true;
};

const draftSummary = computed(() => {
    const d = savedDraft.value?.data;
    if (!d) return '';
    const pay = d.wage_min || d.wage_max ? [d.wage_min, d.wage_max].filter(Boolean).map(money).join(' – ') : null;
    return [pay, d.city, d.vacancies ? t('jobForm.openingsCount', { n: d.vacancies }, Number(d.vacancies)) : null].filter(Boolean).join(' · ');
});

// ── Step checks ─────────────────────────────────────────────────────
// What a step needs before the next one opens, and before posting.
const stepError = ref(false);

const checkStep = (s: Step): boolean => {
    const errors: Partial<Record<JobFormKey, string>> = {};
    const filled = (v: unknown) => v !== '' && v !== null && v !== undefined;

    if (s === 'basics') {
        if (!form.title.trim()) errors.title = t('jobForm.titleRequired');
        if (!form.category) errors.category = t('jobForm.categoryRequired');
        if (!form.state) errors.state = t('jobForm.stateRequired');
        if (!form.city) errors.city = t('jobForm.cityRequired');
        if (!(Number(form.vacancies) >= 1)) errors.vacancies = t('jobForm.vacanciesRequired');
        if (filled(form.wage_min) && filled(form.wage_max) && Number(form.wage_max) < Number(form.wage_min)) errors.wage_max = t('jobForm.wageMaxBelowMin');
        if (hasIncentive.value && !(Number(form.incentive_max) > 0)) errors.incentive_max = t('jobForm.incentiveRequired');
    }

    if (s === 'requirements') {
        if (experienceLevel.value === 'experienced' && !filled(form.experience_min)) errors.experience_min = t('jobForm.experienceMinRequired');
        if (filled(form.age_min) && filled(form.age_max) && Number(form.age_max) < Number(form.age_min)) errors.age_max = t('jobForm.ageMaxBelowMin');
    }

    if (s === 'details') {
        if (!form.description.trim()) errors.description = t('jobForm.descriptionRequired');
        if (!!form.interview_start !== !!form.interview_end) errors.interview_end = t('jobForm.interviewHoursBoth');
        if (form.requires_worker_fee && !(Number(form.worker_fee_amount) > 0)) errors.worker_fee_amount = t('jobForm.feeAmountRequired');
    }

    form.clearErrors(...STEP_FIELDS[s]);
    form.setError(errors);
    stepError.value = Object.keys(errors).length > 0;

    return !stepError.value;
};

const next = () => {
    if (checkStep(step.value)) {
        goTo(STEPS[stepIndex.value + 1]);
    } else {
        // Back up to the note and the first field that needs filling.
        nextTick(() => formTop.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }
};

const back = () => goTo(STEPS[Math.max(0, stepIndex.value - 1)]);

const openStep = (target: Step) => {
    if (canOpen(target)) goTo(target);
};

watch(step, () => (stepError.value = false));

// A field filled in after an error loses its message, and the note at the top
// goes once the step has none left.
watch(
    () => JSON.parse(JSON.stringify(form.data())) as Record<string, unknown>,
    (now, before) => {
        for (const key of Object.keys(form.errors)) {
            if (key in now && JSON.stringify(now[key]) !== JSON.stringify(before?.[key])) form.clearErrors(key as JobFormKey);
        }
        if (stepError.value && !stepHasErrors(step.value)) stepError.value = false;
    },
);

// ── Review ──────────────────────────────────────────────────────────

const salaryText = computed(() => {
    const parts = [form.wage_min, form.wage_max].filter((v) => v !== '' && v !== null);
    return parts.length ? `${parts.map(money).join(' – ')} ${t('jobForm.perMonth')}` : t('jobForm.notDisclosed');
});

const experienceText = computed(() => {
    if (experienceLevel.value === 'any') return t('jobForm.expAny');
    if (experienceLevel.value === 'fresher') return t('jobForm.expFresher');
    const range = ageRange(form.experience_min, form.experience_max);
    return range ? t('jobForm.yearsRange', { range }) : t('jobForm.expExperienced');
});

const shiftText = computed(() => {
    if (form.shift === 'rotational') {
        return form.shift_timings
            .filter((s) => s.start && s.end)
            .map((s, i) => `${t('jobForm.shiftNumber', { n: i + 1 })}: ${hoursLabel(s.start, s.end)}`)
            .join(' · ');
    }
    if (form.shift === 'flexible') {
        return [form.work_hours ? `${form.work_hours} ${form.work_hours_period === 'week' ? t('jobForm.perWeek') : t('jobForm.perDay')}` : null, showCoreHours.value ? hoursLabel(form.core_start, form.core_end) : null]
            .filter(Boolean)
            .join(' · ');
    }
    return hoursLabel(form.shift_start, form.shift_end) ?? '';
});

const interviewText = computed(() =>
    [t(interviewModes.find((m) => m.value === form.interview_mode)?.label ?? 'jobs.interviewOnSite'), daysLabel(form.interview_days, t), hoursLabel(form.interview_start, form.interview_end)]
        .filter(Boolean)
        .join(' · '),
);

interface ReviewRow {
    label: string;
    value: string;
}

const rows = (list: (ReviewRow | null | false)[]) => list.filter((r): r is ReviewRow => !!r && !!r.value);

const review = computed<Record<Exclude<Step, 'review'>, ReviewRow[]>>(() => ({
    basics: rows([
        { label: t('jobForm.titleLabel'), value: form.title },
        { label: t('jobs.filters.category'), value: form.category === OTHER ? t('jobForm.categoryOther') : form.category },
        { label: t('jobForm.jobType'), value: t(jobTypeKey(form.job_type)) },
        { label: t('jobs.vacancies'), value: String(form.vacancies) },
        { label: t('common.location'), value: [form.address, form.city, form.state].filter(Boolean).join(', ') },
        { label: t('jobForm.salaryShort'), value: salaryText.value },
        hasIncentive.value && { label: t('jobForm.incentiveShort'), value: [money(form.incentive_max), form.incentive_type].filter(Boolean).join(' · ') },
        { label: t('jobForm.otherRequirementsShort'), value: form.other_requirements },
    ]),
    requirements: rows([
        { label: t('jobForm.experience'), value: experienceText.value },
        form.education !== '' && { label: t('jobForm.education'), value: t(educationKey(form.education)) },
        form.gender !== '' && { label: t('jobForm.gender'), value: genderOptions.value.find((g) => g.value === form.gender)?.label ?? '' },
        form.english !== '' && { label: t('jobForm.englishLabel'), value: t(englishKey(form.english)) },
        form.reach !== '' && { label: t('jobForm.reachTitle'), value: reachOptions.value.find((r) => r.value === form.reach)?.label ?? '' },
        { label: t('jobForm.relocationShort'), value: form.relocation_allowed ? t('jobForm.relocationYes') : t('jobForm.relocationNo') },
        { label: t('jobForm.skills'), value: form.skills.join(', ') },
        { label: t('jobForm.age'), value: ageRange(form.age_min, form.age_max) ?? '' },
        { label: t('jobForm.languages'), value: form.languages.join(', ') },
        { label: t('jobForm.assets'), value: form.assets.map((a) => t(assetKey(a))).join(', ') },
    ]),
    details: rows([
        { label: t('jobForm.shift'), value: shiftText.value },
        { label: t('jobForm.workDays'), value: daysLabel(form.work_days, t) ?? '' },
        { label: t('jobs.workMode'), value: t(workModes.find((m) => m.value === form.work_mode)?.label ?? 'jobs.workOnSite') },
        { label: t('jobs.interviewMode'), value: interviewText.value },
        form.interview_mode !== 'remote' && !interviewAtJobAddress.value && { label: t('jobForm.interviewAddress'), value: form.interview_address },
        { label: t('jobs.perks'), value: form.perks.join(', ') },
        {
            label: t('jobForm.feeShort'),
            value: form.requires_worker_fee ? [money(form.worker_fee_amount), form.worker_fee_purpose ? t(feePurposeKey(form.worker_fee_purpose)) : null].filter(Boolean).join(' · ') : t('jobForm.noFeeOption'),
        },
        { label: t('jobs.description'), value: form.description.length > 220 ? `${form.description.slice(0, 220)}…` : form.description },
    ]),
}));

const matchParams = computed(() => ({
    category: form.category === OTHER ? '' : form.category,
    skills: form.skills,
    latitude: form.latitude,
    longitude: form.longitude,
    city: form.city,
    reach: form.reach,
    gender: form.gender,
    education: form.education,
    experience_level: experienceLevel.value,
    experience_min: form.experience_min,
}));

// ── Save ────────────────────────────────────────────────────────────
const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';
const textareaClass =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';

// A job that has never been live is still a draft in the making: it gets
// "Save as draft" next to "Post job", and no status picker. Once published, the
// picker is back for closing and reopening.
const neverPublished = !isEdit || !props.job?.published_at;

const submitAs = (status: 'draft' | 'active') => {
    form.status = status;
    submit();
};

const submit = () => {
    if (form.status === 'draft') {
        if (!form.title.trim()) {
            form.setError('title', t('jobForm.titleRequired'));
            goTo('basics');
            return;
        }
    } else if (neverPublished) {
        // Going live: every step must be complete.
        for (const s of ['basics', 'requirements', 'details'] as const) {
            if (!checkStep(s)) {
                goTo(s);
                return;
            }
        }
    } else if (!form.category) {
        // A live job needs a category or a conscious "Other".
        form.setError('category', t('jobForm.categoryRequired'));
        goTo('basics');
        return;
    }

    form.transform((data) =>
        scheduleFields({
            ...data,
            category: data.category === OTHER ? '' : data.category,
            incentive_max: hasIncentive.value ? data.incentive_max : '',
            incentive_type: hasIncentive.value ? data.incentive_type : '',
            interview_address: interviewAtJobAddress.value || data.interview_mode === 'remote' ? '' : data.interview_address,
            worker_fee_purpose: data.requires_worker_fee ? data.worker_fee_purpose : '',
        }),
    );

    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) => {
            const first = Object.keys(errors)[0];
            if (first) goTo(stepOfField(first));
        },
        onSuccess: () => forgetDraft(),
    };

    if (isEdit) {
        form.patch(`/employer/jobs/${props.job!.id}`, options);
    } else {
        form.post('/employer/jobs', options);
    }
};

// Enter in a field: a live job's edit saves; a new job only moves on, and
// never posts by accident from the review step.
const onEnter = () => {
    if (!neverPublished) submit();
    else if (step.value !== 'review') next();
};

const stepIcons = { basics: BriefcaseBusiness, requirements: UserRoundCheck, details: CalendarClock, review: ClipboardCheck } as const;
</script>

<template>
    <Head :title="isEdit ? 'Edit Job' : 'Post a Job'" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="BriefcaseBusiness" :title="isEdit ? $t('jobForm.editTitle') : $t('jobForm.postTitle')" :description="$t('jobForm.subtitle')">
            <template #action>
                <Link href="/employer/jobs" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-muted">
                    <ArrowLeft class="size-4" /> {{ $t('common.back') }}
                </Link>
            </template>
        </PageHeader>

        <Link
            v-if="verificationBlock"
            href="/kyc"
            class="flex items-center gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 transition hover:bg-amber-500/15 dark:text-amber-300"
        >
            <ShieldAlert class="size-5 shrink-0" />
            <span class="flex-1">{{ verificationBlock }}</span>
            <span class="shrink-0 font-semibold underline underline-offset-2">Verify now</span>
        </Link>

        <div
            v-if="!isEdit && freePostAvailable"
            class="flex items-center gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            <Gift class="size-5 shrink-0" />
            <span>{{ $t('jobForm.freePost') }}</span>
        </div>

        <!-- An unfinished job from last time, kept in this browser -->
        <div v-if="savedDraft" class="flex flex-wrap items-center gap-3 rounded-2xl border border-orange-500/25 bg-orange-500/5 px-4 py-3">
            <RotateCcw class="size-5 shrink-0 text-orange-600" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ $t('jobForm.restoreTitle', { title: savedDraft.data.title }) }}</p>
                <p v-if="draftSummary" class="text-xs text-muted-foreground">{{ draftSummary }}</p>
            </div>
            <button type="button" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90" @click="restoreDraft">
                {{ $t('jobForm.restoreBtn') }}
            </button>
            <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-muted-foreground transition hover:text-foreground" @click="dismissDraft">
                <X class="size-3.5" /> {{ $t('jobForm.restoreDismiss') }}
            </button>
        </div>

        <form class="mx-auto flex w-full max-w-5xl flex-col gap-5" @submit.prevent="onEnter">
            <!-- The steps: each opens once reached (an edit, any) -->
            <div ref="formTop" class="scroll-mt-20">
                <ol class="grid grid-cols-4 gap-2" :aria-label="$t('jobForm.stepsLabel')">
                    <li v-for="(s, i) in STEPS" :key="s">
                        <button
                            type="button"
                            :disabled="!canOpen(s)"
                            :aria-current="step === s ? 'step' : undefined"
                            class="flex w-full flex-col items-start gap-1.5 rounded-xl border px-3 py-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="step === s ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                            @click="openStep(s)"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                    :class="
                                        stepHasErrors(s)
                                            ? 'bg-rose-500 text-white'
                                            : i < stepIndex || (isEdit && step !== s)
                                              ? 'bg-emerald-500 text-white'
                                              : step === s
                                                ? 'bg-orange-500 text-white'
                                                : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    <Check v-if="!stepHasErrors(s) && i < stepIndex" class="size-3.5" />
                                    <template v-else>{{ i + 1 }}</template>
                                </span>
                                <component :is="stepIcons[s]" class="hidden size-4 text-orange-500 sm:block" />
                            </span>
                            <span class="text-xs font-semibold leading-tight sm:text-sm">{{ $t(`jobForm.steps.${s}`) }}</span>
                        </button>
                    </li>
                </ol>
            </div>

            <p v-if="support.phone" class="-mt-2 flex items-center justify-end gap-1.5 text-xs text-muted-foreground">
                <LifeBuoy class="size-3.5 text-orange-500" />
                {{ $t('jobForm.needHelp') }}
                <a :href="`tel:${support.phone.replace(/\s+/g, '')}`" class="font-semibold text-foreground underline-offset-2 hover:underline">{{ support.phone }}</a>
                <span v-if="support.hours">· {{ support.hours }}</span>
            </p>

            <p v-if="stepError" class="flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2.5 text-sm text-rose-700 dark:text-rose-300">
                <TriangleAlert class="size-4 shrink-0" /> {{ $t('jobForm.stepIncomplete') }}
            </p>

            <!-- ── Step 1: the basics ───────────────────────────── -->
            <template v-if="step === 'basics'">
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <BriefcaseBusiness class="size-4 text-orange-500" /> {{ $t('jobForm.details') }}
                    </h2>
                    <div class="space-y-5">
                        <div class="grid gap-2">
                            <Label>{{ $t('jobForm.jobType') }}</Label>
                            <ChoiceChips v-model="form.job_type" name="job_type" :options="jobTypeOptions" />
                            <InputError :message="form.errors.job_type" />
                        </div>

                        <!-- The title and the category it belongs to sit side by side: picking a title fills its category -->
                        <div class="grid items-start gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="title">{{ $t('jobForm.titleLabel') }}</Label>
                                <JobTitleCombobox id="title" v-model="form.title" required :disabled="titleLocked" :profiles="jobProfiles" placeholder="e.g. Tailor" @pick="onTitlePick" />
                                <p v-if="titleLocked" class="flex items-start gap-1.5 text-xs text-muted-foreground"><Lock class="mt-0.5 size-3 shrink-0" /> {{ $t('jobForm.titleLocked') }}</p>
                                <p v-else class="text-xs text-muted-foreground">{{ $t('jobForm.titleProfileHint') }}</p>
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="category">{{ $t('jobs.filters.category') }}</Label>
                                <select id="category" ref="categorySelect" v-model="form.category" :class="selectClass">
                                    <option value="">{{ $t('jobForm.selectCategory') }}</option>
                                    <!-- An older job's category that is no longer offered still shows -->
                                    <option v-if="form.category && form.category !== OTHER && !categories.includes(form.category)" :value="form.category">{{ form.category }}</option>
                                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                                    <option :value="OTHER">{{ $t('jobForm.categoryOther') }}</option>
                                </select>
                                <p v-if="categoryHint === 'suggested'" class="flex items-center gap-1 text-xs text-orange-600 dark:text-orange-300">
                                    <Sparkles class="size-3 shrink-0" /> {{ $t('jobForm.categorySuggested') }}
                                </p>
                                <p v-else-if="categoryHint === 'pick'" class="text-xs text-orange-600 dark:text-orange-300">{{ $t('jobForm.categoryPickHint') }}</p>
                                <p v-else-if="categoryHint === 'other'" class="text-xs text-muted-foreground">{{ $t('jobForm.categoryOtherHint') }}</p>
                                <InputError :message="form.errors.category" />
                            </div>
                        </div>

                        <div class="grid max-w-xs gap-2">
                            <Label for="vacancies">{{ $t('jobs.vacancies') }}</Label>
                            <Input id="vacancies" v-model="form.vacancies" type="number" min="1" />
                            <InputError :message="form.errors.vacancies" />
                        </div>
                    </div>
                </section>

                <!-- Location -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <MapPin class="size-4 text-orange-500" /> {{ $t('common.location') }}
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="state">{{ $t('jobs.filters.state') }}</Label>
                            <select id="state" v-model="form.state" :class="selectClass">
                                <option value="">{{ $t('jobForm.selectState') }}</option>
                                <option v-for="s in indianStates" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <InputError :message="form.errors.state" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="city">{{ $t('jobs.filters.city') }}</Label>
                            <select id="city" v-model="form.city" :disabled="!form.state" :class="selectClass" class="disabled:opacity-50">
                                <option value="">{{ form.state ? $t('jobForm.selectCity') : $t('jobForm.selectStateFirst') }}</option>
                                <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <InputError :message="form.errors.city" />
                        </div>
                    </div>

                    <div class="mt-4 grid gap-2">
                        <Label for="address">{{ $t('jobForm.address') }}</Label>
                        <Input id="address" v-model="form.address" :placeholder="$t('jobForm.addressPlaceholder')" />
                        <p class="text-xs text-muted-foreground">{{ $t('jobForm.addressHint') }}</p>
                        <InputError :message="form.errors.address" />
                    </div>

                    <div class="mt-4 grid gap-2">
                        <Label>{{ $t('jobForm.locationOnMap') }}</Label>
                        <p class="text-xs text-muted-foreground">
                            {{ locating ? $t('jobForm.locating') : $t('jobForm.mapHint') }}
                        </p>
                        <JobMap :lat="mapLat" :lng="mapLng" editable height="300px" @move="setPoint" />
                        <InputError :message="form.errors.latitude || form.errors.longitude" />
                    </div>
                </section>

                <!-- Salary -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <IndianRupee class="size-4 text-orange-500" /> {{ $t('jobForm.salaryLabel') }}
                    </h2>
                    <p class="mb-4 text-xs text-muted-foreground">{{ $t('jobForm.salaryHint') }}</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="wage_min">{{ $t('jobForm.salaryFrom') }}</Label>
                            <Input id="wage_min" v-model="form.wage_min" type="number" min="0" step="100" placeholder="10000" />
                            <InputError :message="form.errors.wage_min" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="wage_max">{{ $t('jobForm.salaryTo') }}</Label>
                            <Input id="wage_max" v-model="form.wage_max" type="number" min="0" step="100" placeholder="15000" />
                            <InputError :message="form.errors.wage_max" />
                        </div>
                    </div>

                    <div class="mt-5 grid gap-2">
                        <Label>{{ $t('jobForm.incentiveQuestion') }}</Label>
                        <ChoiceChips v-model="hasIncentive" name="has_incentive" :options="yesNo" />
                    </div>
                    <div v-if="hasIncentive" class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="incentive_max">{{ $t('jobForm.incentiveMax') }}</Label>
                            <Input id="incentive_max" v-model="form.incentive_max" type="number" min="1" step="100" placeholder="2000" />
                            <InputError :message="form.errors.incentive_max" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="incentive_type">{{ $t('jobForm.incentiveType') }}</Label>
                            <Input id="incentive_type" v-model="form.incentive_type" maxlength="100" :placeholder="$t('jobForm.incentiveTypePlaceholder')" />
                            <div class="flex flex-wrap gap-1.5">
                                <button
                                    v-for="type in INCENTIVE_TYPES"
                                    :key="type"
                                    type="button"
                                    class="rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition hover:border-orange-300 hover:text-foreground"
                                    @click="form.incentive_type = type"
                                >
                                    {{ type }}
                                </button>
                            </div>
                            <InputError :message="form.errors.incentive_type" />
                        </div>
                    </div>
                </section>

                <!-- Anything else -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <Label for="other_requirements" class="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <ListChecks class="size-4 text-orange-500" /> {{ $t('jobForm.otherRequirements') }}
                    </Label>
                    <textarea
                        id="other_requirements"
                        v-model="form.other_requirements"
                        rows="2"
                        maxlength="500"
                        :class="textareaClass"
                        class="mt-3"
                        :placeholder="$t('jobForm.otherRequirementsPlaceholder')"
                    />
                    <p class="mt-1 text-right text-xs text-muted-foreground">{{ form.other_requirements.length }}/500</p>
                    <InputError :message="form.errors.other_requirements" />
                </section>
            </template>

            <!-- ── Step 2: who you need ─────────────────────────── -->
            <template v-else-if="step === 'requirements'">
                <p v-if="!isEdit" class="flex items-start gap-2 rounded-xl border border-emerald-500/25 bg-emerald-500/5 px-4 py-2.5 text-sm text-emerald-800 dark:text-emerald-300">
                    <Sparkles class="mt-0.5 size-4 shrink-0" /> {{ autoFillNote }}
                </p>

                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <UserRoundCheck class="size-4 text-orange-500" /> {{ $t('jobForm.requirementsTitle') }}
                    </h2>
                    <div class="space-y-5">
                        <div class="grid gap-2">
                            <Label>{{ $t('jobForm.experience') }}</Label>
                            <ChoiceChips
                                :model-value="experienceLevel"
                                name="experience_level"
                                :options="experienceOptions"
                                :suggested="suggested?.experience_level"
                                :suggested-label="tagFor('experience_level')"
                                @update:model-value="pickRequirement('experience_level', $event)"
                            />
                            <div v-if="experienceLevel === 'experienced'" class="mt-1 grid max-w-md grid-cols-2 gap-3">
                                <div class="grid gap-1">
                                    <Label for="experience_min" class="text-xs text-muted-foreground">{{ $t('jobForm.experienceMin') }}</Label>
                                    <Input id="experience_min" v-model="form.experience_min" type="number" min="0" max="60" placeholder="1" />
                                    <InputError :message="form.errors.experience_min" />
                                </div>
                                <div class="grid gap-1">
                                    <Label for="experience_max" class="text-xs text-muted-foreground">{{ $t('jobForm.experienceMax') }}</Label>
                                    <Input id="experience_max" v-model="form.experience_max" type="number" min="0" max="60" placeholder="5" />
                                    <InputError :message="form.errors.experience_max" />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label>{{ $t('jobForm.education') }}</Label>
                            <ChoiceChips
                                :model-value="form.education"
                                name="education"
                                :options="educationOptions"
                                :suggested="suggested?.education"
                                :suggested-label="tagFor('education')"
                                @update:model-value="pickRequirement('education', $event)"
                            />
                            <InputError :message="form.errors.education" />
                        </div>

                        <div class="grid gap-2">
                            <Label>{{ $t('jobForm.gender') }}</Label>
                            <ChoiceChips
                                :model-value="form.gender"
                                name="gender"
                                :options="genderOptions"
                                :suggested="suggested?.gender"
                                :suggested-label="tagFor('gender')"
                                @update:model-value="pickRequirement('gender', $event)"
                            />
                            <InputError :message="form.errors.gender" />
                        </div>

                        <div class="grid gap-2">
                            <Label>{{ $t('jobForm.englishLabel') }}</Label>
                            <ChoiceChips
                                :model-value="form.english"
                                name="english"
                                :options="englishOptions"
                                :suggested="suggested?.english"
                                :suggested-label="tagFor('english')"
                                @update:model-value="pickRequirement('english', $event)"
                            />
                            <InputError :message="form.errors.english" />
                        </div>
                    </div>
                </section>

                <!-- Where the karigars live -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <MapPin class="size-4 text-orange-500" /> {{ $t('jobForm.reachTitle') }}
                    </h2>
                    <ChoiceChips
                        cards
                        :model-value="form.reach"
                        name="reach"
                        :options="reachOptions"
                        :suggested="suggested?.reach"
                        :suggested-label="tagFor('reach')"
                        @update:model-value="pickRequirement('reach', $event)"
                    />
                    <InputError class="mt-2" :message="form.errors.reach" />

                    <div class="mt-5 grid gap-2">
                        <Label>{{ $t('jobForm.relocationQuestion', { city: form.city || $t('jobForm.theCity') }) }}</Label>
                        <ChoiceChips v-model="form.relocation_allowed" name="relocation_allowed" :options="relocationOptions" />
                        <InputError :message="form.errors.relocation_allowed" />
                    </div>
                </section>

                <!-- Skills -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <Label for="skills" class="mb-3 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Sparkles class="size-4 text-orange-500" /> {{ $t('jobForm.skills') }}
                    </Label>
                    <SkillTagInput
                        id="skills"
                        v-model="form.skills"
                        :suggestions="skillSuggestions"
                        :placeholder="form.category ? $t('jobForm.skillsPlaceholder') : $t('jobForm.skillsPickCategory')"
                    />
                    <InputError :message="form.errors.skills" />
                </section>

                <!-- More, if it matters: age, languages, documents -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-1 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <ListChecks class="size-4 text-orange-500" /> {{ $t('jobForm.moreRequirements') }}
                    </h2>
                    <p class="mb-4 text-xs text-muted-foreground">{{ $t('jobForm.moreRequirementsHint') }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="extra in (['age', 'languages', 'assets'] as const)"
                            :key="extra"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                            :class="extras[extra] ? 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300 hover:text-foreground'"
                            @click="extras[extra] = true"
                        >
                            <Check v-if="extras[extra]" class="size-3.5" />
                            <template v-else>+</template>
                            {{ $t(`jobForm.${extra}`) }}
                        </button>
                    </div>

                    <div v-if="extras.age" class="mt-5 border-t pt-4">
                        <div class="flex items-center justify-between">
                            <Label>{{ $t('jobForm.age') }}</Label>
                            <button type="button" class="text-muted-foreground transition hover:text-rose-600" :aria-label="$t('common.remove')" @click="closeExtra('age')">
                                <X class="size-4" />
                            </button>
                        </div>
                        <div class="mt-2 grid max-w-md grid-cols-2 gap-3">
                            <div class="grid gap-1">
                                <Label for="age_min" class="text-xs text-muted-foreground">{{ $t('jobForm.ageMin') }}</Label>
                                <Input id="age_min" v-model="form.age_min" type="number" :min="detailOptions.age_min" :max="detailOptions.age_max" placeholder="18" />
                                <InputError :message="form.errors.age_min" />
                            </div>
                            <div class="grid gap-1">
                                <Label for="age_max" class="text-xs text-muted-foreground">{{ $t('jobForm.ageMax') }}</Label>
                                <Input id="age_max" v-model="form.age_max" type="number" :min="detailOptions.age_min" :max="detailOptions.age_max" placeholder="40" />
                                <InputError :message="form.errors.age_max" />
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ $t('jobForm.ageHint', { min: detailOptions.age_min }) }}</p>
                    </div>

                    <div v-if="extras.languages" class="mt-5 border-t pt-4">
                        <div class="flex items-center justify-between">
                            <Label>{{ $t('jobForm.languages') }}</Label>
                            <button type="button" class="text-muted-foreground transition hover:text-rose-600" :aria-label="$t('common.remove')" @click="closeExtra('languages')">
                                <X class="size-4" />
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="lang in detailOptions.spoken_languages"
                                :key="lang"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition"
                                :class="form.languages.includes(lang) ? 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300'"
                                @click="toggleIn('languages', lang)"
                            >
                                {{ form.languages.includes(lang) ? '✓ ' : '' }}{{ lang }}
                            </button>
                        </div>
                        <InputError :message="form.errors.languages" />
                    </div>

                    <div v-if="extras.assets" class="mt-5 border-t pt-4">
                        <div class="flex items-center justify-between">
                            <Label>{{ $t('jobForm.assets') }}</Label>
                            <button type="button" class="text-muted-foreground transition hover:text-rose-600" :aria-label="$t('common.remove')" @click="closeExtra('assets')">
                                <X class="size-4" />
                            </button>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="asset in ASSETS"
                                :key="asset"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition"
                                :class="form.assets.includes(asset) ? 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300'"
                                @click="toggleIn('assets', asset)"
                            >
                                {{ form.assets.includes(asset) ? '✓ ' : '' }}{{ $t(assetKey(asset)) }}
                            </button>
                        </div>
                        <InputError :message="form.errors.assets" />
                    </div>
                </section>
            </template>

            <!-- ── Step 3: timings, interview, pay terms, description ─ -->
            <template v-else-if="step === 'details'">
                <!-- Timings -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Sun class="size-4 text-orange-500" /> {{ $t('jobForm.timingsTitle') }}
                    </h2>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="grid content-start gap-2">
                            <Label for="shift">{{ $t('jobForm.shift') }}</Label>
                            <select id="shift" v-model="form.shift" :class="selectClass">
                                <option value="">—</option>
                                <option value="day">{{ $t('jobForm.shiftDay') }}</option>
                                <option value="night">{{ $t('jobForm.shiftNight') }}</option>
                                <option value="rotational">{{ $t('jobForm.shiftRotational') }}</option>
                                <option value="flexible">{{ $t('jobForm.shiftFlexible') }}</option>
                            </select>
                            <InputError :message="form.errors.shift" />

                            <!-- Rotational: one row per shift. -->
                            <template v-if="form.shift === 'rotational'">
                                <div v-for="(timing, i) in form.shift_timings" :key="i" class="grid gap-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-muted-foreground">{{ $t('jobForm.shiftNumber', { n: i + 1 }) }}</span>
                                        <button v-if="form.shift_timings.length > 1" type="button" class="text-xs text-muted-foreground transition hover:text-rose-600" @click="removeShiftTiming(i)">
                                            {{ $t('jobForm.removeShift') }}
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <Input v-model="timing.start" type="time" :aria-label="$t('jobForm.shiftFrom')" />
                                        <Input v-model="timing.end" type="time" :aria-label="$t('jobForm.shiftTo')" />
                                    </div>
                                    <InputError :message="errorAt(`shift_timings.${i}.start`) || errorAt(`shift_timings.${i}.end`)" />
                                </div>
                                <button
                                    v-if="form.shift_timings.length < MAX_SHIFT_TIMINGS"
                                    type="button"
                                    class="justify-self-start text-xs font-semibold text-orange-600 transition hover:text-orange-700 dark:text-orange-300"
                                    @click="addShiftTiming"
                                >
                                    {{ $t('jobForm.addShiftTiming') }}
                                </button>
                                <InputError :message="errorAt('shift_timings')" />
                            </template>

                            <!-- Flexible: total hours, and an optional core window. -->
                            <template v-else-if="form.shift === 'flexible'">
                                <div class="grid gap-1">
                                    <Label for="work_hours" class="text-xs text-muted-foreground">{{ $t('jobForm.workHours') }}</Label>
                                    <div class="grid grid-cols-2 gap-2">
                                        <Input id="work_hours" v-model="form.work_hours" type="number" min="1" :max="form.work_hours_period === 'week' ? 168 : 24" placeholder="8" />
                                        <select v-model="form.work_hours_period" :class="selectClass">
                                            <option value="day">{{ $t('jobForm.perDay') }}</option>
                                            <option value="week">{{ $t('jobForm.perWeek') }}</option>
                                        </select>
                                    </div>
                                    <InputError :message="form.errors.work_hours || form.errors.work_hours_period" />
                                </div>
                                <label class="flex items-center gap-2 text-sm">
                                    <input v-model="showCoreHours" type="checkbox" class="accent-orange-600" />
                                    {{ $t('jobForm.addCoreHours') }}
                                </label>
                                <template v-if="showCoreHours">
                                    <p class="text-xs text-muted-foreground">{{ $t('jobForm.coreHoursHint') }}</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="grid gap-1">
                                            <Label for="core_start" class="text-xs text-muted-foreground">{{ $t('jobForm.coreStart') }}</Label>
                                            <Input id="core_start" v-model="form.core_start" type="time" />
                                        </div>
                                        <div class="grid gap-1">
                                            <Label for="core_end" class="text-xs text-muted-foreground">{{ $t('jobForm.coreEnd') }}</Label>
                                            <Input id="core_end" v-model="form.core_end" type="time" />
                                        </div>
                                    </div>
                                    <InputError :message="form.errors.core_start || form.errors.core_end" />
                                </template>
                            </template>

                            <!-- Day / night: one pair of times, filled in with the usual hours. -->
                            <template v-else-if="form.shift">
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="grid gap-1">
                                        <Label for="shift_start" class="text-xs text-muted-foreground">{{ $t('jobForm.shiftFrom') }}</Label>
                                        <Input id="shift_start" v-model="form.shift_start" type="time" />
                                    </div>
                                    <div class="grid gap-1">
                                        <Label for="shift_end" class="text-xs text-muted-foreground">{{ $t('jobForm.shiftTo') }}</Label>
                                        <Input id="shift_end" v-model="form.shift_end" type="time" />
                                    </div>
                                </div>
                                <InputError :message="form.errors.shift_start || form.errors.shift_end" />
                            </template>
                        </div>

                        <div class="grid content-start gap-2">
                            <Label>{{ $t('jobForm.workDays') }}</Label>
                            <DayPicker v-model="form.work_days" :label="$t('jobForm.workDays')" />
                            <p class="text-xs text-muted-foreground">{{ $t('jobForm.timingsHint') }}</p>
                            <InputError :message="form.errors.work_days" />
                        </div>
                    </div>
                </section>

                <!-- Work & interview -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <House class="size-4 text-orange-500" /> {{ $t('jobForm.workSection') }}
                    </h2>
                    <Label class="mb-2 block">{{ $t('jobForm.workModeQuestion') }}</Label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label
                            v-for="m in workModes"
                            :key="m.value"
                            class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                            :class="form.work_mode === m.value ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.work_mode" type="radio" name="work_mode" :value="m.value" class="accent-orange-600" />
                                <span class="flex items-center gap-1.5 text-sm font-semibold"><component :is="m.icon" class="size-4 text-orange-500" /> {{ $t(m.label) }}</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ $t(m.desc) }}</span>
                        </label>
                    </div>
                    <InputError class="mt-2" :message="form.errors.work_mode" />

                    <Label class="mt-5 mb-2 block">{{ $t('jobForm.interviewModeQuestion') }}</Label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label
                            v-for="m in interviewModes"
                            :key="m.value"
                            class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                            :class="form.interview_mode === m.value ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.interview_mode" type="radio" name="interview_mode" :value="m.value" class="accent-orange-600" @change="interviewTouched = true" />
                                <span class="flex items-center gap-1.5 text-sm font-semibold"><component :is="m.icon" class="size-4 text-orange-500" /> {{ $t(m.label) }}</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ $t(m.desc) }}</span>
                        </label>
                    </div>
                    <InputError class="mt-2" :message="form.errors.interview_mode" />
                    <p v-if="aiOptions.call_available" class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground">
                        <Bot class="mt-0.5 size-3.5 shrink-0 text-orange-500" /> {{ $t('jobForm.workModeAiHint') }}
                    </p>

                    <!-- When (and where) karigars can come for the interview -->
                    <div class="mt-5 grid gap-4 border-t pt-5">
                        <Label>{{ $t('jobForm.interviewTitle') }}</Label>
                        <div class="grid gap-2">
                            <span class="text-xs text-muted-foreground">{{ $t('jobForm.interviewDays') }}</span>
                            <DayPicker v-model="form.interview_days" :label="$t('jobForm.interviewDays')" />
                            <InputError :message="form.errors.interview_days" />
                        </div>
                        <div class="grid max-w-md grid-cols-2 gap-3">
                            <div class="grid gap-1">
                                <Label for="interview_start" class="text-xs text-muted-foreground">{{ $t('jobForm.interviewFrom') }}</Label>
                                <Input id="interview_start" v-model="form.interview_start" type="time" />
                            </div>
                            <div class="grid gap-1">
                                <Label for="interview_end" class="text-xs text-muted-foreground">{{ $t('jobForm.interviewTo') }}</Label>
                                <Input id="interview_end" v-model="form.interview_end" type="time" />
                            </div>
                        </div>
                        <InputError :message="form.errors.interview_start || form.errors.interview_end" />

                        <template v-if="form.interview_mode !== 'remote'">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="interviewAtJobAddress" type="checkbox" class="accent-orange-600" />
                                {{ $t('jobForm.interviewAtJobAddress') }}
                            </label>
                            <div v-if="!interviewAtJobAddress" class="grid gap-2">
                                <Label for="interview_address">{{ $t('jobForm.interviewAddress') }}</Label>
                                <Input id="interview_address" v-model="form.interview_address" maxlength="255" :placeholder="$t('jobForm.interviewAddressPlaceholder')" />
                                <InputError :message="form.errors.interview_address" />
                            </div>
                        </template>
                    </div>
                </section>

                <!-- Perks and any fee -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Gift class="size-4 text-orange-500" /> {{ $t('jobs.perks') }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="perk in perkChips"
                            :key="perk"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                            :class="form.perks.includes(perk) ? 'border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300 hover:text-foreground'"
                            @click="togglePerk(perk)"
                        >
                            {{ form.perks.includes(perk) ? '✓ ' : '' }}{{ perk }}
                        </button>
                    </div>
                    <div class="mt-3 flex max-w-md gap-2">
                        <Input v-model="newPerk" :placeholder="$t('jobForm.addPerkPlaceholder')" maxlength="40" @keydown.enter.prevent="addPerk" />
                        <button type="button" class="shrink-0 rounded-md border px-3 text-xs font-semibold text-muted-foreground transition hover:bg-muted" @click="addPerk">
                            {{ $t('jobForm.addPerk') }}
                        </button>
                    </div>
                    <InputError :message="form.errors.perks" />

                    <h3 class="mt-6 mb-3 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Wallet class="size-4 text-orange-500" /> {{ $t('jobForm.workerPayQuestion') }}
                    </h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                            :class="!form.requires_worker_fee ? 'border-emerald-500 bg-emerald-500/5 ring-2 ring-emerald-500/20' : 'hover:border-emerald-300'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.requires_worker_fee" type="radio" name="requires_worker_fee" :value="false" class="accent-emerald-600" />
                                <span class="flex items-center gap-1.5 text-sm font-semibold"><BadgeCheck class="size-4 text-emerald-600" /> {{ $t('jobForm.noFeeOption') }}</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ $t('jobForm.noFeeHint') }}</span>
                        </label>
                        <label
                            class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                            :class="form.requires_worker_fee ? 'border-amber-500 bg-amber-500/5 ring-2 ring-amber-500/20' : 'hover:border-amber-300'"
                        >
                            <span class="flex items-center gap-2">
                                <input v-model="form.requires_worker_fee" type="radio" name="requires_worker_fee" :value="true" class="accent-amber-600" />
                                <span class="text-sm font-semibold">{{ $t('jobForm.feeOption') }}</span>
                            </span>
                            <span class="text-xs text-muted-foreground">{{ $t('jobForm.feeHint') }}</span>
                        </label>
                    </div>
                    <InputError class="mt-2" :message="form.errors.requires_worker_fee" />

                    <div v-if="form.requires_worker_fee" class="mt-4 grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="worker_fee_amount">{{ $t('jobForm.feeAmount') }}</Label>
                            <Input id="worker_fee_amount" v-model="form.worker_fee_amount" type="number" min="1" step="0.01" placeholder="e.g. 500" />
                            <InputError :message="form.errors.worker_fee_amount" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="worker_fee_purpose">{{ $t('jobForm.feePurpose') }}</Label>
                            <select id="worker_fee_purpose" v-model="form.worker_fee_purpose" :class="selectClass">
                                <option value="">{{ $t('jobForm.feePurposePick') }}</option>
                                <option v-for="p in FEE_PURPOSES" :key="p" :value="p">{{ $t(feePurposeKey(p)) }}</option>
                            </select>
                            <InputError :message="form.errors.worker_fee_purpose" />
                        </div>
                    </div>
                </section>

                <!-- Description -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <div class="grid gap-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Label for="description" class="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                <Pencil class="size-4 text-orange-500" /> {{ $t('jobs.description') }}
                            </Label>
                            <div class="flex items-center gap-2">
                                <div class="inline-flex rounded-lg border p-0.5 text-xs font-semibold">
                                    <button
                                        v-for="lang in (['en', 'hi'] as const)"
                                        :key="lang"
                                        type="button"
                                        class="rounded-md px-2 py-0.5 transition"
                                        :class="aiLanguage === lang ? 'bg-orange-500/10 text-orange-600 dark:text-orange-300' : 'text-muted-foreground hover:text-foreground'"
                                        :disabled="!canSuggest || suggesting"
                                        @click="setAiLanguage(lang)"
                                    >
                                        {{ lang === 'en' ? 'English' : 'हिंदी' }}
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    :disabled="!canSuggest || suggesting"
                                    class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:opacity-50"
                                    @click="fetchSuggestions"
                                >
                                    <Sparkles class="size-3.5 text-orange-500" />
                                    {{ suggesting ? $t('jobForm.aiWriting') : $t('jobForm.aiSuggest') }}
                                </button>
                            </div>
                        </div>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="6"
                            :class="textareaClass"
                            placeholder="Describe the work, requirements and timing…"
                            @focus="onDescriptionFocus"
                        />

                        <!-- AI drafts: tap one to drop it in, then edit freely -->
                        <p v-if="suggesting" class="text-xs text-muted-foreground">{{ $t('jobForm.aiWritingHint') }}</p>
                        <p v-else-if="suggestError" class="text-xs text-muted-foreground">{{ $t('jobForm.aiFailed') }}</p>
                        <div v-else-if="suggestions.length" class="grid gap-2">
                            <p class="text-xs text-muted-foreground">{{ $t('jobForm.aiPick') }}</p>
                            <button
                                v-for="(s, i) in suggestions"
                                :key="i"
                                type="button"
                                class="rounded-xl border border-dashed bg-muted/30 p-3 text-left text-sm text-muted-foreground transition hover:border-orange-500/50 hover:bg-muted/60 hover:text-foreground"
                                @click="useSuggestion(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                        <InputError :message="form.errors.description" />
                    </div>
                </section>
            </template>

            <!-- ── Step 4: review, contact and post ─────────────── -->
            <template v-else>
                <div class="grid items-start gap-5 lg:grid-cols-[1fr_22rem]">
                    <div class="flex flex-col gap-5">
                        <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                            <h2 class="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                <ClipboardCheck class="size-4 text-orange-500" /> {{ $t('jobForm.reviewTitle') }}
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">{{ $t('jobForm.reviewHint') }}</p>

                            <div v-for="part in (['basics', 'requirements', 'details'] as const)" :key="part" class="mt-5 border-t pt-4">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold">{{ $t(`jobForm.steps.${part}`) }}</h3>
                                    <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 transition hover:text-orange-700 dark:text-orange-300" @click="goTo(part)">
                                        <Pencil class="size-3" /> {{ $t('jobForm.edit') }}
                                    </button>
                                </div>
                                <dl class="mt-2 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-[10rem_1fr]">
                                    <template v-for="row in review[part]" :key="row.label">
                                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                                        <dd class="font-medium break-words">{{ row.value }}</dd>
                                    </template>
                                </dl>
                            </div>
                        </section>

                        <!-- How karigars respond -->
                        <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                <Phone class="size-4 text-orange-500" /> {{ $t('jobForm.contactQuestion') }}
                            </h2>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label
                                    v-for="m in contactModes"
                                    :key="m.value"
                                    class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                                    :class="form.contact_mode === m.value ? 'border-orange-500 bg-orange-500/5 ring-2 ring-orange-500/20' : 'hover:border-orange-300'"
                                >
                                    <span class="flex items-center gap-2">
                                        <input v-model="form.contact_mode" type="radio" name="contact_mode" :value="m.value" class="accent-orange-600" />
                                        <span class="text-sm font-semibold">{{ $t(m.label) }}</span>
                                    </span>
                                    <span class="text-xs text-muted-foreground">{{ $t(m.desc) }}</span>
                                </label>
                            </div>
                            <InputError class="mt-2" :message="form.errors.contact_mode" />

                            <div v-if="form.contact_mode !== 'apply'" class="mt-4 grid gap-4 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="contact_name">{{ $t('jobForm.contactName') }}</Label>
                                    <Input id="contact_name" v-model="form.contact_name" maxlength="100" :placeholder="$t('jobForm.contactNamePlaceholder')" />
                                    <InputError :message="form.errors.contact_name" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="contact_phone">{{ $t('jobForm.phoneWorkersCall') }}</Label>
                                    <Input id="contact_phone" v-model="form.contact_phone" type="tel" placeholder="+91 98765 43210" />
                                    <InputError :message="form.errors.contact_phone" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="contact_designation">{{ $t('jobForm.contactDesignation') }}</Label>
                                    <Input id="contact_designation" v-model="form.contact_designation" maxlength="100" :placeholder="$t('jobForm.contactDesignationPlaceholder')" />
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="d in DESIGNATIONS"
                                            :key="d"
                                            type="button"
                                            class="rounded-full border px-2.5 py-0.5 text-xs transition"
                                            :class="form.contact_designation === d ? 'border-orange-500 bg-orange-500/10 text-orange-700 dark:text-orange-300' : 'text-muted-foreground hover:border-orange-300'"
                                            @click="form.contact_designation = d"
                                        >
                                            {{ d }}
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.contact_designation" />
                                </div>
                                <p class="text-xs text-muted-foreground sm:col-span-3">{{ $t('jobForm.phonePublicHint') }}</p>
                            </div>
                        </section>

                        <!-- Posting settings -->
                        <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                <Settings2 class="size-4 text-orange-500" /> {{ $t('jobForm.settings') }}
                            </h2>
                            <div class="grid gap-2">
                                <Label>{{ $t('jobForm.urgencyQuestion') }}</Label>
                                <ChoiceChips v-model="form.hiring_urgency" cards name="hiring_urgency" :options="urgencyOptions" />
                                <InputError :message="form.errors.hiring_urgency" />
                            </div>
                            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div v-if="!neverPublished" class="grid gap-2">
                                    <Label for="status">{{ $t('kyc.status') }}</Label>
                                    <select id="status" v-model="form.status" :class="selectClass">
                                        <option value="active">{{ $t('status.active') }}</option>
                                        <option value="closed">{{ $t('status.closed') }}</option>
                                    </select>
                                    <InputError :message="form.errors.status" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="expires_at">{{ $t('jobForm.expiresOn') }}</Label>
                                    <Input id="expires_at" v-model="form.expires_at" type="date" />
                                    <InputError :message="form.errors.expires_at" />
                                </div>
                            </div>
                        </section>

                        <!-- AI help: the employer's own switches, under the admin's -->
                        <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                            <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                                <Bot class="size-4 text-orange-500" /> {{ $t('jobForm.aiTitle') }}
                            </h2>
                            <div class="grid gap-3">
                                <label
                                    v-for="sw in aiSwitches"
                                    :key="sw.key"
                                    class="flex items-start justify-between gap-4 rounded-xl border p-4"
                                    :class="sw.available ? 'cursor-pointer' : 'opacity-60'"
                                >
                                    <span class="grid gap-1">
                                        <span class="text-sm font-semibold">{{ $t(sw.title) }}</span>
                                        <span class="text-xs text-muted-foreground">{{ $t(sw.desc) }}</span>
                                        <span v-if="sw.note" class="text-xs font-medium text-amber-600">{{ $t(sw.note) }}</span>
                                    </span>
                                    <input v-model="form[sw.key]" type="checkbox" :disabled="!sw.available" class="mt-1 size-5 shrink-0 accent-orange-600" />
                                </label>
                                <!-- AI calls are paid for with credits -->
                                <div
                                    v-if="aiOptions.call_available && credits?.enabled"
                                    class="flex flex-wrap items-center justify-between gap-2 rounded-xl px-4 py-3 text-xs"
                                    :class="needsRecharge ? 'bg-amber-500/10 text-amber-800 dark:text-amber-200' : 'bg-muted/60 text-muted-foreground'"
                                >
                                    <span>
                                        {{ $t('jobForm.aiCredits', { balance: credits.balance }) }}
                                        <template v-if="needsRecharge"> · {{ $t('jobForm.aiCreditsNeeded', { min: credits.min_for_job }) }}</template>
                                    </span>
                                    <a href="/employer/ai-credits" target="_blank" class="font-semibold text-orange-700 underline-offset-2 hover:underline dark:text-orange-300">
                                        {{ $t('jobForm.aiCreditsRecharge') }}
                                    </a>
                                </div>
                                <InputError :message="form.errors.ai_call_enabled" />
                            </div>
                        </section>
                    </div>

                    <div class="flex flex-col gap-5 lg:sticky lg:top-20">
                        <MatchingKarigarsPanel :params="matchParams" />
                        <p class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-200">
                            <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ $t('jobForm.noPaymentWarning') }}
                        </p>
                        <p v-if="neverPublished" class="text-xs text-muted-foreground">{{ $t('jobForm.draftHint') }}</p>
                    </div>
                </div>
            </template>

            <!-- The bar that moves between steps, and saves -->
            <div class="sticky bottom-0 z-20 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6 lg:mx-0 lg:rounded-2xl lg:border">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="mr-auto min-w-0">
                        <p class="text-sm font-semibold">{{ $t(`jobForm.steps.${step}`) }}</p>
                        <p class="text-xs text-muted-foreground">{{ $t('jobForm.stepOf', { n: stepIndex + 1, total: STEPS.length }) }}</p>
                    </div>
                    <button
                        v-if="stepIndex > 0"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-xl px-3 py-2.5 text-sm font-medium text-muted-foreground transition hover:text-foreground"
                        @click="back"
                    >
                        <ArrowLeft class="size-4" /> {{ $t('jobForm.back') }}
                    </button>
                    <button
                        v-if="neverPublished"
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-sm font-semibold transition hover:bg-muted active:scale-95 disabled:opacity-50"
                        @click="submitAs('draft')"
                    >
                        {{ $t('jobForm.saveDraft') }}
                    </button>
                    <button
                        v-else
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold transition active:scale-95 disabled:opacity-50"
                        :class="step === 'review' ? 'bg-primary text-white shadow-lg shadow-orange-600/25 hover:opacity-90' : 'border hover:bg-muted'"
                    >
                        {{ $t('jobForm.saveChanges') }}
                    </button>
                    <button
                        v-if="step !== 'review'"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95"
                        @click="next"
                    >
                        {{ $t('jobForm.next') }} <ArrowRight class="size-4" />
                    </button>
                    <button
                        v-else-if="neverPublished"
                        type="button"
                        :disabled="form.processing"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                        @click="submitAs('active')"
                    >
                        {{ $t('jobForm.postBtn') }}
                    </button>
                </div>
            </div>
        </form>
    </div>
</template>
