<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Camera, FileText, IndianRupee, MapPin, Trash2, Upload, UserRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import JobMap from '@/components/JobMap.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { citiesFor, indianStates } from '@/data/indianLocations';
import { commonSkills } from '@/data/skills';

interface WorkerProfile {
    phone: string | null;
    skills: string[] | null;
    experience_years: number | null;
    bio: string | null;
    expected_wage: string | null;
    wage_type: string | null;
    city: string | null;
    state: string | null;
    latitude: string | null;
    longitude: string | null;
    available: boolean;
    payout_upi: string | null;
    avatar_url: string | null;
}

interface Resume {
    name: string | null;
    uploaded_ago: string | null;
    characters: number;
    max_characters: number;
}

const props = defineProps<{ profile: WorkerProfile; email: string | null; resume: Resume | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My Profile', href: '/worker/profile' }],
    },
});

const { t } = useI18n();

const preview = ref<string | null>(props.profile.avatar_url);

const form = useForm<{
    email: string;
    phone: string;
    skills: string[];
    experience_years: number | string;
    bio: string;
    expected_wage: number | string;
    wage_type: string;
    city: string;
    state: string;
    latitude: number | string;
    longitude: number | string;
    available: boolean;
    payout_upi: string;
    avatar: File | null;
}>({
    email: props.email ?? '',
    phone: props.profile.phone ?? '',
    skills: props.profile.skills ?? [],
    experience_years: props.profile.experience_years ?? '',
    bio: props.profile.bio ?? '',
    expected_wage: props.profile.expected_wage ?? '',
    wage_type: props.profile.wage_type ?? '',
    city: props.profile.city ?? '',
    state: props.profile.state ?? '',
    latitude: props.profile.latitude ?? '',
    longitude: props.profile.longitude ?? '',
    available: props.profile.available ?? true,
    payout_upi: props.profile.payout_upi ?? '',
    avatar: null,
});

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';
const textareaClass =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';

const initial = computed(() => (props.profile.phone ?? 'W').charAt(0).toUpperCase());

const onAvatar = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.avatar = file;
    if (file) preview.value = URL.createObjectURL(file);
};

const cities = computed(() => citiesFor(form.state));
watch(() => form.state, () => {
    if (form.city && !cities.value.includes(form.city)) form.city = '';
});

// ── Map (replaces manual lat/long inputs) ───────────────────────────
const mapLat = ref<number | null>(form.latitude ? Number(form.latitude) : null);
const mapLng = ref<number | null>(form.longitude ? Number(form.longitude) : null);
const locating = ref(false);

const setPoint = (lat: number, lng: number) => {
    mapLat.value = lat;
    mapLng.value = lng;
    form.latitude = String(lat);
    form.longitude = String(lng);
};

let geoTimer: ReturnType<typeof setTimeout> | undefined;
watch([() => form.city, () => form.state], ([city, state]) => {
    if (!city && !state) return;
    clearTimeout(geoTimer);
    geoTimer = setTimeout(async () => {
        locating.value = true;
        try {
            const q = new URLSearchParams({
                format: 'json',
                limit: '1',
                q: [city, state, 'India'].filter(Boolean).join(', '),
            });
            const res = await fetch(`https://nominatim.openstreetmap.org/search?${q}`, { headers: { Accept: 'application/json' } });
            const hits = await res.json();
            if (hits[0]) setPoint(Number(hits[0].lat), Number(hits[0].lon));
        } catch {
            // best-effort only
        } finally {
            locating.value = false;
        }
    }, 800);
});

// File uploads don't survive a real PATCH, so spoof it over POST.
const submit = () => {
    form.transform((data) => ({ ...data, _method: 'patch' })).post('/worker/profile', { preserveScroll: true });
};

// ── Resume (its own routes, so its own form — it saves on pick) ──────
const resumeForm = useForm<{ resume: File | null }>({ resume: null });

const onResume = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (!file) return;
    resumeForm.resume = file;
    resumeForm.post('/worker/resume', {
        preserveScroll: true,
        // Let the same file be picked again after a failed upload.
        onFinish: () => (input.value = ''),
    });
};

const removeResume = () => {
    if (window.confirm(t('resume.confirmRemove'))) {
        router.delete('/worker/resume', { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="My Profile" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="UserRound" :title="$t('profile.workerTitle')" :description="$t('profile.workerSubtitle')" />

        <form class="space-y-5" @submit.prevent="submit">
            <!-- Avatar + basics -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                    <div class="relative">
                        <div class="flex size-24 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-3xl font-bold text-white shadow-lg shadow-orange-500/25">
                            <img v-if="preview" :src="preview" alt="Avatar" class="size-full object-cover" />
                            <span v-else>{{ initial }}</span>
                        </div>
                        <label class="absolute -bottom-2 -right-2 flex size-9 cursor-pointer items-center justify-center rounded-full border-2 border-background bg-orange-500 text-white shadow-md transition hover:bg-orange-600">
                            <Camera class="size-4" />
                            <input type="file" accept="image/*" class="hidden" @change="onAvatar" />
                        </label>
                    </div>
                    <div class="flex-1 space-y-4">
                        <div class="grid gap-2">
                            <Label for="email">{{ $t('profile.email') }}</Label>
                            <Input id="email" type="email" v-model="form.email" placeholder="you@example.com" />
                            <p class="text-xs text-muted-foreground">{{ $t('profile.emailHint') }}</p>
                            <InputError :message="form.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="phone">{{ $t('common.phone') }}</Label>
                            <Input id="phone" v-model="form.phone" placeholder="+91…" />
                            <InputError :message="form.errors.phone" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="skills">{{ $t('profile.skills') }}</Label>
                            <SkillTagInput id="skills" v-model="form.skills" :suggestions="commonSkills" placeholder="e.g. Plumbing — type or pick, it becomes a tag" />
                            <InputError :message="form.errors.skills" />
                        </div>
                        <InputError :message="form.errors.avatar" />
                    </div>
                </div>
            </section>

            <!-- Work details -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <IndianRupee class="size-4 text-orange-500" /> Work & rate
                </h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="experience_years">{{ $t('profile.experienceYears') }}</Label>
                        <Input id="experience_years" type="number" min="0" v-model="form.experience_years" />
                        <InputError :message="form.errors.experience_years" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expected_wage">{{ $t('profile.expectedWageLabel') }}</Label>
                        <Input id="expected_wage" type="number" min="0" step="0.01" v-model="form.expected_wage" />
                        <InputError :message="form.errors.expected_wage" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="wage_type">Wage type</Label>
                        <select id="wage_type" v-model="form.wage_type" :class="selectClass">
                            <option value="">—</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                        <InputError :message="form.errors.wage_type" />
                    </div>
                </div>

                <div class="mt-4 grid gap-2">
                    <Label for="bio">{{ $t('profile.bio') }}</Label>
                    <textarea id="bio" v-model="form.bio" rows="4" :class="textareaClass" :placeholder="$t('profile.bioPlaceholder')" />
                    <InputError :message="form.errors.bio" />
                </div>

                <label class="mt-4 flex items-center gap-3 rounded-xl border bg-muted/30 px-4 py-3">
                    <Checkbox id="available" v-model="form.available" />
                    <span>
                        <span class="text-sm font-medium">{{ $t('profile.availableForWork') }}</span>
                        <span class="block text-xs text-muted-foreground">{{ $t('profile.availableHint') }}</span>
                    </span>
                </label>

                <div class="mt-4">
                    <Label for="payout_upi">{{ $t('profile.payoutUpi') }}</Label>
                    <Input id="payout_upi" v-model="form.payout_upi" placeholder="name@bank" />
                    <p class="mt-1 text-xs text-muted-foreground">{{ $t('profile.payoutHint') }}</p>
                    <InputError :message="form.errors.payout_upi" />
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
                            <option v-for="st in indianStates" :key="st" :value="st">{{ st }}</option>
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
                    <Label>{{ $t('profile.locationOnMap') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ locating ? $t('jobForm.locating') : $t('profile.mapHint') }}
                    </p>
                    <JobMap :lat="mapLat" :lng="mapLng" editable height="280px" @move="setPoint" />
                    <InputError :message="form.errors.latitude || form.errors.longitude" />
                </div>
            </section>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    {{ $t('common.saveChanges') }}
                </button>
            </div>
        </form>

        <!-- Resume — saves on its own, independent of the form above -->
        <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
            <h2 class="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                <FileText class="size-4 text-orange-500" /> {{ $t('resume.title') }}
            </h2>
            <p class="mt-1 text-xs text-muted-foreground">{{ $t('resume.subtitle') }}</p>

            <!-- Uploaded -->
            <div v-if="resume" class="mt-4 flex flex-wrap items-center gap-3 rounded-xl border border-orange-500/20 bg-orange-500/5 p-3">
                <FileText class="size-5 shrink-0 text-orange-600" />
                <div class="min-w-0 flex-1">
                    <a
                        :href="'/worker/resume'"
                        target="_blank"
                        class="block truncate text-sm font-medium underline-offset-2 hover:underline"
                    >{{ resume.name }}</a>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="resume.uploaded_ago">{{ $t('resume.uploadedAgo', { when: resume.uploaded_ago }) }} · </span>
                        {{ $t('resume.parsed', { count: resume.characters }) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted" :class="{ 'pointer-events-none opacity-50': resumeForm.processing }">
                        <Upload class="size-3.5" />
                        {{ resumeForm.processing ? $t('resume.uploading') : $t('resume.replace') }}
                        <input type="file" accept="application/pdf,.pdf" class="hidden" @change="onResume" />
                    </label>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:hover:bg-rose-500/10"
                        @click="removeResume"
                    >
                        <Trash2 class="size-3.5" /> {{ $t('common.remove') }}
                    </button>
                </div>
            </div>

            <!-- Empty -->
            <label
                v-else
                class="mt-4 flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed bg-muted/30 px-4 py-6 text-center transition hover:border-orange-500/50 hover:bg-muted/50"
                :class="{ 'pointer-events-none opacity-50': resumeForm.processing }"
            >
                <Upload class="size-5 text-orange-500" />
                <span class="text-xs font-medium">{{ resumeForm.processing ? $t('resume.uploading') : $t('resume.upload') }}</span>
                <input type="file" accept="application/pdf,.pdf" class="hidden" @change="onResume" />
            </label>

            <p class="mt-2 text-xs text-muted-foreground">{{ $t('resume.hint') }}</p>
            <InputError :message="resumeForm.errors.resume" />
        </section>
    </div>
</template>
