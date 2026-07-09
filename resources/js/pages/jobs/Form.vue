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

const props = defineProps<{ job: Job | null; defaultPhone: string | null }>();

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
    { value: 'apply', label: 'Apply through app', desc: 'Workers apply here; you review applicants.' },
    { value: 'call', label: 'Direct call', desc: 'Your number is shown on the job — workers call you.' },
    { value: 'both', label: 'Both', desc: 'Workers can apply in the app or call you directly.' },
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
        <PageHeader :icon="BriefcaseBusiness" :title="isEdit ? 'Edit Job' : 'Post a Job'" description="Fill in the details to reach the right workers">
            <template #action>
                <Link href="/employer/jobs" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-muted">
                    <ArrowLeft class="size-4" /> Back
                </Link>
            </template>
        </PageHeader>

        <form class="space-y-5" @submit.prevent="submit">
            <!-- Basics -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <BriefcaseBusiness class="size-4 text-orange-500" /> Job details
                </h2>
                <div class="space-y-4">
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input id="title" v-model="form.title" required placeholder="e.g. Experienced plumber needed" />
                        <InputError :message="form.errors.title" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="description">Job Description</Label>
                        <textarea id="description" v-model="form.description" rows="5" required :class="textareaClass" placeholder="Describe the work, requirements and timing…" />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="category">Category</Label>
                            <select id="category" v-model="form.category" :class="selectClass">
                                <option value="">Select a category</option>
                                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                            </select>
                            <InputError :message="form.errors.category" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="skills">Skills</Label>
                            <SkillTagInput id="skills" v-model="form.skills" :suggestions="commonSkills" placeholder="e.g. Welding — type or pick, it becomes a tag" />
                            <InputError :message="form.errors.skills" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- Wage -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <IndianRupee class="size-4 text-orange-500" /> Compensation
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="wage_min">Wage min (₹)</Label>
                        <Input id="wage_min" type="number" min="0" step="0.01" v-model="form.wage_min" />
                        <InputError :message="form.errors.wage_min" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="wage_max">Wage max (₹)</Label>
                        <Input id="wage_max" type="number" min="0" step="0.01" v-model="form.wage_max" />
                        <InputError :message="form.errors.wage_max" />
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
            </section>

            <!-- Location -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <MapPin class="size-4 text-orange-500" /> Location
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="state">State</Label>
                        <select id="state" v-model="form.state" :class="selectClass">
                            <option value="">Select state</option>
                            <option v-for="s in indianStates" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <InputError :message="form.errors.state" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="city">City</Label>
                        <select id="city" v-model="form.city" :disabled="!form.state" :class="selectClass" class="disabled:opacity-50">
                            <option value="">{{ form.state ? 'Select city' : 'Select state first' }}</option>
                            <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <InputError :message="form.errors.city" />
                    </div>
                </div>

                <div class="mt-4 grid gap-2">
                    <Label>Job location on map</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ locating ? 'Locating your city on the map…' : 'Select a state & city to place the pin, then drag it (or tap the map) to the exact spot workers should reach.' }}
                    </p>
                    <JobMap :lat="mapLat" :lng="mapLng" editable height="300px" @move="setPoint" />
                    <InputError :message="form.errors.latitude || form.errors.longitude" />
                </div>
            </section>

            <!-- Shift & perks -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <Sun class="size-4 text-orange-500" /> Shift &amp; perks
                </h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="shift">Shift</Label>
                        <select id="shift" v-model="form.shift" :class="selectClass">
                            <option value="">—</option>
                            <option value="day">Day shift</option>
                            <option value="night">Night shift</option>
                            <option value="rotational">Rotational shift</option>
                        </select>
                        <InputError :message="form.errors.shift" />
                    </div>
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-1.5"><Gift class="size-3.5 text-orange-500" /> Perks</Label>
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
                    <Wallet class="size-4 text-orange-500" /> Does the worker pay anything to take this job?
                </h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                        :class="!form.requires_worker_fee ? 'border-emerald-500 bg-emerald-500/5 ring-2 ring-emerald-500/20' : 'hover:border-emerald-300'"
                    >
                        <span class="flex items-center gap-2">
                            <input v-model="form.requires_worker_fee" type="radio" name="requires_worker_fee" :value="false" class="accent-emerald-600" />
                            <span class="flex items-center gap-1.5 text-sm font-semibold"><BadgeCheck class="size-4 text-emerald-600" /> No — free to join</span>
                        </span>
                        <span class="text-xs text-muted-foreground">Worker pays nothing. The job shows a "No fee" badge — workers trust these more.</span>
                    </label>
                    <label
                        class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition"
                        :class="form.requires_worker_fee ? 'border-amber-500 bg-amber-500/5 ring-2 ring-amber-500/20' : 'hover:border-amber-300'"
                    >
                        <span class="flex items-center gap-2">
                            <input v-model="form.requires_worker_fee" type="radio" name="requires_worker_fee" :value="true" class="accent-amber-600" />
                            <span class="text-sm font-semibold">Yes — worker pays a fee</span>
                        </span>
                        <span class="text-xs text-muted-foreground">e.g. security deposit, tools or uniform charge. The amount is shown on the job post.</span>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.requires_worker_fee" />

                <div v-if="form.requires_worker_fee" class="mt-4 grid max-w-xs gap-2">
                    <Label for="worker_fee_amount">Fee amount (₹)</Label>
                    <Input id="worker_fee_amount" v-model="form.worker_fee_amount" type="number" min="1" step="0.01" placeholder="e.g. 500" />
                    <InputError :message="form.errors.worker_fee_amount" />
                </div>
            </section>

            <!-- How workers respond -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <Phone class="size-4 text-orange-500" /> How should workers contact you?
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
                            <span class="text-sm font-semibold">{{ m.label }}</span>
                        </span>
                        <span class="text-xs text-muted-foreground">{{ m.desc }}</span>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.contact_mode" />

                <div v-if="form.contact_mode !== 'apply'" class="mt-4 grid max-w-sm gap-2">
                    <Label for="contact_phone">Phone number workers will call</Label>
                    <Input id="contact_phone" v-model="form.contact_phone" type="tel" placeholder="+91 98765 43210" />
                    <p class="text-xs text-muted-foreground">This number will be visible on the job post to everyone.</p>
                    <InputError :message="form.errors.contact_phone" />
                </div>
            </section>

            <!-- Settings -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <Settings2 class="size-4 text-orange-500" /> Posting settings
                </h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="vacancies">Vacancies</Label>
                        <Input id="vacancies" type="number" min="1" v-model="form.vacancies" />
                        <InputError :message="form.errors.vacancies" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="status">Status</Label>
                        <select id="status" v-model="form.status" :class="selectClass">
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="closed">Closed</option>
                        </select>
                        <InputError :message="form.errors.status" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="expires_at">Expires on</Label>
                        <Input id="expires_at" type="date" v-model="form.expires_at" />
                        <InputError :message="form.errors.expires_at" />
                    </div>
                </div>
            </section>

            <div class="flex items-center justify-end gap-3">
                <Link href="/employer/jobs" class="rounded-xl px-4 py-2.5 text-sm font-medium text-muted-foreground transition hover:text-foreground">Cancel</Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    {{ isEdit ? 'Update job' : 'Post job' }}
                </button>
            </div>
        </form>
    </div>
</template>
