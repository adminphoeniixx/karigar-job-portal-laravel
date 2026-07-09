<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Building2, ImagePlus, MapPin } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import JobMap from '@/components/JobMap.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { citiesFor, indianStates } from '@/data/indianLocations';

interface EmployerProfile {
    company_name: string | null;
    phone: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    latitude: string | null;
    longitude: string | null;
    about: string | null;
    logo_url: string | null;
}

const props = defineProps<{ profile: EmployerProfile }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Company Profile', href: '/employer/profile' }],
    },
});

const preview = ref<string | null>(props.profile.logo_url);

const form = useForm<{
    company_name: string;
    phone: string;
    address: string;
    city: string;
    state: string;
    latitude: number | string;
    longitude: number | string;
    about: string;
    logo: File | null;
}>({
    company_name: props.profile.company_name ?? '',
    phone: props.profile.phone ?? '',
    address: props.profile.address ?? '',
    city: props.profile.city ?? '',
    state: props.profile.state ?? '',
    latitude: props.profile.latitude ?? '',
    longitude: props.profile.longitude ?? '',
    about: props.profile.about ?? '',
    logo: null,
});

const textareaClass =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';

const initial = computed(() => (props.profile.company_name ?? 'C').charAt(0).toUpperCase());

const onLogo = (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.logo = file;
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

// Geocode the typed address (best-effort) so the pin lands nearby;
// the user can always drag it to the exact spot.
let geoTimer: ReturnType<typeof setTimeout> | undefined;
watch([() => form.address, () => form.city, () => form.state], ([address, city, state]) => {
    if (!city && !state && !address) return;
    clearTimeout(geoTimer);
    geoTimer = setTimeout(async () => {
        locating.value = true;
        try {
            const q = new URLSearchParams({
                format: 'json',
                limit: '1',
                q: [address, city, state, 'India'].filter(Boolean).join(', '),
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
const submit = () =>
    form.transform((data) => ({ ...data, _method: 'patch' })).post('/employer/profile', { preserveScroll: true });
</script>

<template>
    <Head title="Company Profile" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Building2" title="Company Profile" description="Update your company details and location" />

        <form class="space-y-5" @submit.prevent="submit">
            <!-- Logo + name -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                    <div class="relative">
                        <div class="flex size-24 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-rose-500 to-orange-600 text-3xl font-bold text-white shadow-lg shadow-rose-500/25">
                            <img v-if="preview" :src="preview" alt="Logo" class="size-full object-cover" />
                            <span v-else>{{ initial }}</span>
                        </div>
                        <label class="absolute -bottom-2 -right-2 flex size-9 cursor-pointer items-center justify-center rounded-full border-2 border-background bg-rose-500 text-white shadow-md transition hover:bg-rose-600">
                            <ImagePlus class="size-4" />
                            <input type="file" accept="image/*" class="hidden" @change="onLogo" />
                        </label>
                    </div>
                    <div class="flex-1 space-y-4">
                        <div class="grid gap-2">
                            <Label for="company_name">Company name</Label>
                            <Input id="company_name" v-model="form.company_name" />
                            <InputError :message="form.errors.company_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="phone">Phone</Label>
                            <Input id="phone" v-model="form.phone" placeholder="+91…" />
                            <InputError :message="form.errors.phone" />
                        </div>
                        <InputError :message="form.errors.logo" />
                    </div>
                </div>
            </section>

            <!-- About + address -->
            <section class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <div class="grid gap-2">
                    <Label for="about">About</Label>
                    <textarea id="about" v-model="form.about" rows="4" :class="textareaClass" placeholder="Tell workers about your company…" />
                    <InputError :message="form.errors.about" />
                </div>
                <div class="mt-4 grid gap-2">
                    <Label for="address">Address</Label>
                    <textarea id="address" v-model="form.address" rows="2" :class="textareaClass" />
                    <InputError :message="form.errors.address" />
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
                        <select
                            id="state"
                            v-model="form.state"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20"
                        >
                            <option value="">Select state</option>
                            <option v-for="st in indianStates" :key="st" :value="st">{{ st }}</option>
                        </select>
                        <InputError :message="form.errors.state" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="city">City</Label>
                        <select
                            id="city"
                            v-model="form.city"
                            :disabled="!form.state"
                            class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 disabled:opacity-50"
                        >
                            <option value="">{{ form.state ? 'Select city' : 'Select state first' }}</option>
                            <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <InputError :message="form.errors.city" />
                    </div>
                </div>

                <div class="mt-4 grid gap-2">
                    <Label>Location on map</Label>
                    <p class="text-xs text-muted-foreground">
                        {{ locating ? 'Locating your address on the map…' : 'Fill your address above and the pin moves there — drag it (or tap the map) to fine-tune.' }}
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
                    Save changes
                </button>
            </div>
        </form>
    </div>
</template>
