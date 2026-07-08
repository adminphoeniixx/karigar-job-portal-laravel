<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, BriefcaseBusiness, IndianRupee, MapPin, Phone, Settings2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
}

const props = defineProps<{ job: Job | null; defaultPhone: string | null }>();

const isEdit = props.job !== null;

defineOptions({
    layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }] },
});

const page = usePage();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

const skillsText = ref((props.job?.skills ?? []).join(', '));

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
});

const contactModes = [
    { value: 'apply', label: 'Apply through app', desc: 'Workers apply here; you review applicants.' },
    { value: 'call', label: 'Direct call', desc: 'Your number is shown on the job — workers call you.' },
    { value: 'both', label: 'Both', desc: 'Workers can apply in the app or call you directly.' },
] as const;

const cities = computed(() => citiesFor(form.state));
watch(() => form.state, () => {
    if (form.city && !cities.value.includes(form.city)) form.city = '';
});

const selectClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';
const textareaClass =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20';

const submit = () => {
    form.transform((data) => ({
        ...data,
        skills: skillsText.value.split(',').map((s) => s.trim()).filter(Boolean),
    }));

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
                        <Label for="description">Description</Label>
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
                            <Label for="skills">Skills (comma separated)</Label>
                            <Input id="skills" v-model="skillsText" list="job-skill-options" placeholder="Welding, Fitting" />
                            <datalist id="job-skill-options">
                                <option v-for="s in commonSkills" :key="s" :value="s" />
                            </datalist>
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
                    <div class="grid gap-2">
                        <Label for="latitude">Latitude</Label>
                        <Input id="latitude" type="number" step="any" v-model="form.latitude" />
                        <InputError :message="form.errors.latitude" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="longitude">Longitude</Label>
                        <Input id="longitude" type="number" step="any" v-model="form.longitude" />
                        <InputError :message="form.errors.longitude" />
                    </div>
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
