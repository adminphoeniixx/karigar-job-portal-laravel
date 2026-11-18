<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, Bot, BriefcaseBusiness, Building2, Gift, Handshake, House, IndianRupee, MapPin, Phone, Settings2, ShieldAlert, Shuffle, Sparkles, Sun, Video, Wallet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import JobMap from '@/components/JobMap.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { citiesFor, indianStates } from '@/data/indianLocations';
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
    ai_shortlist_enabled: boolean;
    ai_call_enabled: boolean;
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
    jobProfiles: { title: string; category: string }[];
    perkOptions: string[];
    // Which AI switches the admin has on platform-wide; the rest show disabled.
    aiOptions: {
        shortlist_available: boolean;
        call_available: boolean;
        // AI calls are paid for with credits; `can_enable` false = recharge first.
        credits?: { enabled: boolean; balance: number; min_for_job: number; can_enable: boolean };
    };
}>();

const isEdit = props.job !== null;

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }] },
});

const page = usePage();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

const form = useForm({
    title: props.job?.title ?? '',
    description: props.job?.description ?? '',
    category: props.job?.category ?? '',
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
    experience_min: props.job?.experience_min ?? '',
    experience_max: props.job?.experience_max ?? '',
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
    gender: props.job?.gender ?? '',
    perks: props.job?.perks ?? [],
    requires_worker_fee: props.job?.requires_worker_fee ?? false,
    worker_fee_amount: props.job?.worker_fee_amount ?? '',
    ai_shortlist_enabled: props.job?.ai_shortlist_enabled ?? true,
    // A new job starts with AI calls on only when there are credits to pay for them.
    ai_call_enabled: props.job?.ai_call_enabled ?? (props.aiOptions.credits?.can_enable ?? true),
});

// Shifts. A day or night shift starts with the usual hours filled in; a
// rotational job lists each shift; a flexible one gives its total hours and,
// optionally, a core window that must fit inside them.
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
        const untouched =
            !form.shift_start ||
            (wasDefault && form.shift_start === wasDefault.start && form.shift_end === wasDefault.end);

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

// The chosen category's skills; the employer can still type any other.
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
        if (typed.length < 3) {
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
                if (titleSuggestion.value) applyTitleSuggestion(titleSuggestion.value);
            } catch {
                // A suggestion is a convenience; posting never waits on it.
            }
        }, 450);
    },
);

// The chosen category's skills, ordered for the title when it suggested this
// category; the employer can still type any other.
const skillSuggestions = computed(() =>
    titleSuggestion.value?.category === form.category && titleSuggestion.value.skills.length
        ? titleSuggestion.value.skills
        : (props.categorySkills[form.category] ?? []),
);

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

// Switching AI calls on needs credits, unless the job already had them on.
const credits = computed(() => props.aiOptions.credits);
const needsRecharge = computed(
    () => !!credits.value?.enabled && !credits.value.can_enable && !(props.job?.ai_call_enabled && props.job?.published_at),
);

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
watch(() => form.work_mode, (mode) => {
    if (!interviewTouched.value) form.interview_mode = mode === 'remote' ? 'remote' : 'on_site';
});

const cities = computed(() => citiesFor(form.state));
watch(() => form.state, () => {
    if (form.city && !cities.value.includes(form.city)) form.city = '';
});

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
watch(() => form.address, (val) => {
    if (val.trim().length === 0 || val.trim().length >= 4) geocode();
});

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
        if (form.category) params.set('category', form.category);
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
    form.transform((data) => scheduleFields({ ...data }));

    if (isEdit) {
        form.patch(`/employer/jobs/${props.job!.id}`);
    } else {
        form.post('/employer/jobs');
    }
};
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

        <form class="grid items-start gap-5 lg:grid-cols-2" @submit.prevent="submit">
            <div class="flex flex-col gap-5">
                <!-- Basics -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <BriefcaseBusiness class="size-4 text-orange-500" /> {{ $t('jobForm.details') }}
                    </h2>
                    <div class="space-y-4">
                        <div class="grid gap-2">
                            <Label for="title">{{ $t('jobForm.titleLabel') }}</Label>
                            <Input id="title" v-model="form.title" required list="job-profiles" autocomplete="off" placeholder="e.g. Tailor" />
                            <datalist id="job-profiles">
                                <option v-for="p in jobProfiles" :key="`${p.category}-${p.title}`" :value="p.title">{{ p.category }}</option>
                            </datalist>
                            <p class="text-xs text-muted-foreground">{{ $t('jobForm.titleProfileHint') }}</p>
                            <InputError :message="form.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <Label for="description">{{ $t('jobs.description') }}</Label>
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
                                rows="5"
                                required
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

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="category">{{ $t('jobs.filters.category') }}</Label>
                                <select id="category" v-model="form.category" :class="selectClass">
                                    <option value="">{{ $t('jobForm.selectCategory') }}</option>
                                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                                </select>
                                <p v-if="autoCategory && form.category === autoCategory" class="flex items-center gap-1 text-xs text-orange-600 dark:text-orange-300">
                                    <Sparkles class="size-3" /> {{ $t('jobForm.categorySuggested') }}
                                </p>
                                <InputError :message="form.errors.category" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="skills">{{ $t('jobForm.skills') }}</Label>
                                <SkillTagInput
                                    id="skills"
                                    v-model="form.skills"
                                    :suggestions="skillSuggestions"
                                    :placeholder="form.category ? $t('jobForm.skillsPlaceholder') : $t('jobForm.skillsPickCategory')"
                                />
                                <InputError :message="form.errors.skills" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="experience_min">{{ $t('jobForm.experienceMin') }}</Label>
                                <Input id="experience_min" v-model="form.experience_min" type="number" min="0" max="60" placeholder="0" />
                                <InputError :message="form.errors.experience_min" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="experience_max">{{ $t('jobForm.experienceMax') }}</Label>
                                <Input id="experience_max" v-model="form.experience_max" type="number" min="0" max="60" placeholder="5" />
                                <InputError :message="form.errors.experience_max" />
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Wage -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <IndianRupee class="size-4 text-orange-500" /> {{ $t('jobForm.compensation') }}
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="wage_min">{{ $t('jobForm.wageMin') }}</Label>
                            <Input id="wage_min" type="number" min="0" step="0.01" v-model="form.wage_min" />
                            <InputError :message="form.errors.wage_min" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="wage_max">{{ $t('jobForm.wageMax') }}</Label>
                            <Input id="wage_max" type="number" min="0" step="0.01" v-model="form.wage_max" />
                            <InputError :message="form.errors.wage_max" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="wage_type">{{ $t('jobForm.wageType') }}</Label>
                            <select id="wage_type" v-model="form.wage_type" :class="selectClass" disabled>
                                <option value="monthly">{{ $t('jobForm.monthly') }}</option>
                            </select>
                            <InputError :message="form.errors.wage_type" />
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
                    <p v-if="aiOptions.call_available" class="mt-3 flex items-start gap-1.5 text-xs text-muted-foreground"><Bot class="mt-0.5 size-3.5 shrink-0 text-orange-500" /> {{ $t('jobForm.workModeAiHint') }}</p>
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
            </div>

            <div class="flex flex-col gap-5">
                <!-- Shift & perks -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Sun class="size-4 text-orange-500" /> {{ $t('jobForm.shiftPerks') }}
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2">
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
                                        <button
                                            v-if="form.shift_timings.length > 1"
                                            type="button"
                                            class="text-xs text-muted-foreground transition hover:text-rose-600"
                                            @click="removeShiftTiming(i)"
                                        >
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

                            <Label for="gender" class="mt-2">{{ $t('jobForm.gender') }}</Label>
                            <select id="gender" v-model="form.gender" :class="selectClass">
                                <option value="">{{ $t('jobForm.genderAny') }}</option>
                                <option value="male">{{ $t('jobForm.genderMale') }}</option>
                                <option value="female">{{ $t('jobForm.genderFemale') }}</option>
                                <option value="both">{{ $t('jobForm.genderBoth') }}</option>
                            </select>
                            <InputError :message="form.errors.gender" />
                        </div>
                        <div class="grid gap-2">
                            <Label class="flex items-center gap-1.5"><Gift class="size-3.5 text-orange-500" /> {{ $t('jobs.perks') }}</Label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="perk in perkChips"
                                    :key="perk"
                                    type="button"
                                    class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                                    :class="form.perks.includes(perk)
                                        ? 'border-orange-500 bg-orange-500/10 text-orange-600 dark:text-orange-300'
                                        : 'text-muted-foreground hover:border-orange-300 hover:text-foreground'"
                                    @click="togglePerk(perk)"
                                >
                                    {{ form.perks.includes(perk) ? '✓ ' : '' }}{{ perk }}
                                </button>
                            </div>
                            <div class="flex gap-2">
                                <Input v-model="newPerk" :placeholder="$t('jobForm.addPerkPlaceholder')" maxlength="40" @keydown.enter.prevent="addPerk" />
                                <button type="button" class="shrink-0 rounded-md border px-3 text-xs font-semibold text-muted-foreground transition hover:bg-muted" @click="addPerk">
                                    {{ $t('jobForm.addPerk') }}
                                </button>
                            </div>
                            <InputError :message="form.errors.perks" />
                        </div>
                    </div>
                </section>

                <!-- Worker fee -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Wallet class="size-4 text-orange-500" /> {{ $t('jobForm.workerPayQuestion') }}
                    </h2>
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

                    <div v-if="form.requires_worker_fee" class="mt-4 grid max-w-xs gap-2">
                        <Label for="worker_fee_amount">{{ $t('jobForm.feeAmount') }}</Label>
                        <Input id="worker_fee_amount" v-model="form.worker_fee_amount" type="number" min="1" step="0.01" placeholder="e.g. 500" />
                        <InputError :message="form.errors.worker_fee_amount" />
                    </div>
                </section>

                <!-- How workers respond -->
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
                            <InputError :message="form.errors.contact_designation" />
                        </div>
                        <p class="text-xs text-muted-foreground sm:col-span-3">{{ $t('jobForm.phonePublicHint') }}</p>
                    </div>
                </section>

                <!-- Settings -->
                <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                    <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                        <Settings2 class="size-4 text-orange-500" /> {{ $t('jobForm.settings') }}
                    </h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="vacancies">{{ $t('jobs.vacancies') }}</Label>
                            <Input id="vacancies" type="number" min="1" v-model="form.vacancies" />
                            <InputError :message="form.errors.vacancies" />
                        </div>
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
                            <Input id="expires_at" type="date" v-model="form.expires_at" />
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

                <p v-if="neverPublished" class="text-right text-xs text-muted-foreground">{{ $t('jobForm.draftHint') }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 lg:col-span-2">
                <Link href="/employer/jobs" class="rounded-xl px-4 py-2.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">{{ $t('common.cancel') }}</Link>
                <button
                    v-if="neverPublished"
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl border px-5 py-2.5 text-sm font-semibold transition hover:bg-muted active:scale-95 disabled:opacity-50"
                    @click="submitAs('draft')"
                >
                    {{ $t('jobForm.saveDraft') }}
                </button>
                <button
                    v-if="neverPublished"
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                    @click="submitAs('active')"
                >
                    {{ $t('jobForm.postBtn') }}
                </button>
                <button
                    v-else
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    {{ $t('jobForm.updateBtn') }}
                </button>
            </div>
        </form>
    </div>
</template>
