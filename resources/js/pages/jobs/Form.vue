<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, BriefcaseBusiness, Gift, IndianRupee, MapPin, Phone, Settings2, Sun, Wallet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import JobMap from '@/components/JobMap.vue';
import SkillTagInput from '@/components/SkillTagInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { citiesFor, indianStates } from '@/data/indianLocations';
import { commonSkills } from '@/data/skills';

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
    status: string;
    expires_at: string | null;
    contact_mode: 'apply' | 'call' | 'both';
    contact_phone: string | null;
    shift: 'day' | 'night' | 'rotational' | null;
    perks: string[] | null;
    requires_worker_fee: boolean;
    worker_fee_amount: string | null;
}

const props = defineProps<{ job: Job | null; defaultPhone: string | null; freePostAvailable?: boolean }>();

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
    wage_min: props.job?.wage_min ?? '',
    wage_max: props.job?.wage_max ?? '',
    wage_type: props.job?.wage_type ?? '',
    city: props.job?.city ?? '',
    state: props.job?.state ?? '',
    latitude: props.job?.latitude ?? '',
    longitude: props.job?.longitude ?? '',
    vacancies: props.job?.vacancies ?? 1,
    status: props.job?.status ?? 'active',
    expires_at: props.job?.expires_at?.slice(0, 10) ?? '',
    contact_mode: props.job?.contact_mode ?? 'apply',
    contact_phone: props.job?.contact_phone ?? props.defaultPhone ?? '',
    shift: props.job?.shift ?? '',
    perks: props.job?.perks ?? [],
    requires_worker_fee: props.job?.requires_worker_fee ?? false,
    worker_fee_amount: props.job?.worker_fee_amount ?? '',
});

const perkOptions = ['Food', 'Accommodation', 'Travel allowance', 'Bonus', 'Overtime pay', 'Weekly off'];

const togglePerk = (perk: string) => {
    form.perks = form.perks.includes(perk) ? form.perks.filter((x) => x !== perk) : [...form.perks, perk];
};

const contactModes = [
    { value: 'apply', label: 'jobForm.applyThroughApp', desc: 'jobForm.applyThroughAppDesc' },
    { value: 'call', label: 'jobForm.directCall', desc: 'jobForm.directCallDesc' },
    { value: 'both', label: 'jobForm.both', desc: 'jobForm.bothDesc' },
] as const;

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

// When the employer picks a city, centre the pin there via OpenStreetMap's
// free geocoder so they only need to fine-tune it.
let geoTimer: ReturnType<typeof setTimeout> | undefined;
watch([() => form.city, () => form.state], ([city, state]) => {
    if (!city || !state) return;
    clearTimeout(geoTimer);
    geoTimer = setTimeout(async () => {
        locating.value = true;
        try {
            const q = new URLSearchParams({ format: 'json', limit: '1', country: 'India', state, city });
            const res = await fetch(`https://nominatim.openstreetmap.org/search?${q}`, {
                headers: { Accept: 'application/json' },
            });
            const hits = await res.json();
            if (hits[0]) setPoint(Number(hits[0].lat), Number(hits[0].lon));
        } catch {
            // Geocoding is best-effort — the employer can always drop the pin manually.
        } finally {
            locating.value = false;
        }
    }, 600);
});

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';
const textareaClass =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';

const submit = () => {
    if (isEdit) {
        form.patch(`/employer/jobs/${props.job!.id}`);
    } else {
        form.post('/employer/jobs');
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Job' : 'Post a Job'" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="BriefcaseBusiness" :title="isEdit ? $t('jobForm.editTitle') : $t('jobForm.postTitle')" :description="$t('jobForm.subtitle')">
            <template #action>
                <Link href="/employer/jobs" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-muted">
                    <ArrowLeft class="size-4" /> {{ $t('common.back') }}
                </Link>
            </template>
        </PageHeader>

        <div
            v-if="!isEdit && freePostAvailable"
            class="flex items-center gap-3 rounded-2xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            <Gift class="size-5 shrink-0" />
            <span>{{ $t('jobForm.freePost') }}</span>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <!-- Basics -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <BriefcaseBusiness class="size-4 text-orange-500" /> {{ $t('jobForm.details') }}
                </h2>
                <div class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="title">{{ $t('jobForm.titleLabel') }}</Label>
                        <Input id="title" v-model="form.title" required placeholder="e.g. Experienced plumber needed" />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">{{ $t('jobs.description') }}</Label>
                        <textarea id="description" v-model="form.description" rows="5" required :class="textareaClass" placeholder="Describe the work, requirements and timing…" />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category">{{ $t('jobs.filters.category') }}</Label>
                            <select id="category" v-model="form.category" :class="selectClass">
                                <option value="">{{ $t('jobForm.selectCategory') }}</option>
                                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <InputError :message="form.errors.category" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="skills">{{ $t('jobForm.skills') }}</Label>
                            <SkillTagInput id="skills" v-model="form.skills" :suggestions="commonSkills" placeholder="e.g. Welding — type or pick, it becomes a tag" />
                            <InputError :message="form.errors.skills" />
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
                        <select id="wage_type" v-model="form.wage_type" :class="selectClass">
                            <option value="">—</option>
                            <option value="hourly">{{ $t('jobForm.hourly') }}</option>
                            <option value="daily">{{ $t('jobForm.daily') }}</option>
                            <option value="monthly">{{ $t('jobForm.monthly') }}</option>
                        </select>
                        <InputError :message="form.errors.wage_type" />
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
                    <Label>{{ $t('jobForm.locationOnMap') }}</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ locating ? $t('jobForm.locating') : $t('jobForm.mapHint') }}
                    </p>
                    <JobMap :lat="mapLat" :lng="mapLng" editable height="300px" @move="setPoint" />
                    <InputError :message="form.errors.latitude || form.errors.longitude" />
                </div>
            </section>

            <!-- Shift & perks -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <Sun class="size-4 text-orange-500" /> {{ $t('jobForm.shiftPerks') }}
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="shift">{{ $t('jobForm.shift') }}</Label>
                        <select id="shift" v-model="form.shift" :class="selectClass">
                            <option value="">—</option>
                            <option value="day">{{ $t('jobs.dayShift') }}</option>
                            <option value="night">{{ $t('jobs.nightShift') }}</option>
                            <option value="rotational">{{ $t('jobs.rotationalShift') }}</option>
                        </select>
                        <InputError :message="form.errors.shift" />
                    </div>
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-1.5"><Gift class="size-3.5 text-orange-500" /> {{ $t('jobs.perks') }}</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="perk in perkOptions"
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

                <div v-if="form.contact_mode !== 'apply'" class="mt-4 grid max-w-sm gap-2">
                    <Label for="contact_phone">{{ $t('jobForm.phoneWorkersCall') }}</Label>
                    <Input id="contact_phone" v-model="form.contact_phone" type="tel" placeholder="+91 98765 43210" />
                    <p class="text-xs text-muted-foreground">{{ $t('jobForm.phonePublicHint') }}</p>
                    <InputError :message="form.errors.contact_phone" />
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
                    <div class="grid gap-2">
                        <Label for="status">{{ $t('kyc.status') }}</Label>
                        <select id="status" v-model="form.status" :class="selectClass">
                            <option value="draft">{{ $t('status.draft') }}</option>
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

            <div class="flex items-center justify-end gap-3">
                <Link href="/employer/jobs" class="rounded-xl px-4 py-2.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">{{ $t('common.cancel') }}</Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    {{ isEdit ? $t('jobForm.updateBtn') : $t('jobForm.postBtn') }}
                </button>
            </div>
        </form>
    </div>
</template>
