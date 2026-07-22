<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Camera, IndianRupee, MapPin, UserRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
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

const props = defineProps<{ profile: WorkerProfile; email: string | null }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My Profile', href: '/worker/profile' }],
    },
});

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
    </div>
</template>
