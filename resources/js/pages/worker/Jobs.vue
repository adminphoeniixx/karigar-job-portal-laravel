<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, Briefcase, IndianRupee, MapPin, Search, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { citiesFor, indianStates } from '@/data/indianLocations';
import { commonSkills } from '@/data/skills';

interface Job {
    id: number;
    title: string;
    category: string | null;
    city: string | null;
    state: string | null;
    wage_min: string | null;
    wage_max: string | null;
    wage_type: string | null;
    employer: { id: number; name: string };
}

const props = defineProps<{
    jobs: { data: Job[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: Record<string, string | number | null>;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Browse Jobs', href: '/worker/jobs' }] } });

const page = usePage();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

const form = reactive({
    q: (props.filters.q as string) ?? '',
    category: (props.filters.category as string) ?? '',
    skill: (props.filters.skill as string) ?? '',
    state: (props.filters.state as string) ?? '',
    city: (props.filters.city as string) ?? '',
});

const cities = computed(() => citiesFor(form.state));

// Reset city when the state changes to something that doesn't contain it.
watch(() => form.state, () => {
    if (form.city && !cities.value.includes(form.city)) form.city = '';
});

const searching = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const run = () => {
    const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
    searching.value = true;
    router.get('/worker/jobs', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['jobs', 'filters'],
        onFinish: () => (searching.value = false),
    });
};

// Instant search: debounce text typing; fire dropdowns immediately.
const debouncedRun = () => {
    clearTimeout(timer);
    timer = setTimeout(run, 300);
};

watch(() => form.q, (val) => {
    // Search as you type from 2 characters onward, or when cleared.
    if (val.length === 0 || val.length >= 2) debouncedRun();
});
watch([() => form.category, () => form.skill, () => form.state, () => form.city], run);

const clearAll = () => {
    form.q = form.category = form.skill = form.state = form.city = '';
    run();
};

const hasFilters = computed(() => Object.values(form).some((v) => v !== ''));

const wage = (j: Job) => {
    if (!j.wage_min && !j.wage_max) return 'Negotiable';
    const range = [j.wage_min, j.wage_max].filter(Boolean).join('–');
    return `${range}${j.wage_type ? ' / ' + j.wage_type : ''}`;
};
</script>

<template>
    <Head title="Browse Jobs" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Briefcase" title="Browse Jobs" description="Find work near you and apply in one tap" />

        <!-- Filters -->
        <div class="rounded-2xl border bg-card p-4 shadow-sm">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                <!-- Search (instant) -->
                <div class="relative lg:col-span-2">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" :class="searching && 'animate-pulse text-orange-500'" />
                    <input v-model="form.q" placeholder="Search jobs — type to see results…" class="w-full rounded-xl border bg-background py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>

                <!-- Category dropdown (admin-managed) -->
                <select v-model="form.category" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                    <option value="">All categories</option>
                    <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>

                <!-- Skill (datalist suggestions, free text) -->
                <input v-model="form.skill" list="skill-options" placeholder="Skill" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                <datalist id="skill-options">
                    <option v-for="s in commonSkills" :key="s" :value="s" />
                </datalist>

                <!-- State → City dependent -->
                <select v-model="form.state" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                    <option value="">All states</option>
                    <option v-for="s in indianStates" :key="s" :value="s">{{ s }}</option>
                </select>
                <select v-model="form.city" :disabled="!form.state" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40 disabled:opacity-50">
                    <option value="">{{ form.state ? 'All cities' : 'Select state first' }}</option>
                    <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                </select>
            </div>

            <div v-if="hasFilters" class="mt-3 flex justify-end">
                <button class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition hover:text-foreground" @click="clearAll">
                    <X class="size-3.5" /> Clear filters
                </button>
            </div>
        </div>

        <!-- Results -->
        <div v-if="jobs.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="job in jobs.data"
                :key="job.id"
                :href="`/worker/jobs/${job.id}`"
                class="group flex flex-col rounded-2xl border bg-card p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
            >
                <div class="flex items-center justify-between">
                    <span v-if="job.category" class="rounded-full bg-orange-500/10 px-2.5 py-0.5 text-xs font-semibold text-orange-600 dark:text-orange-300">{{ job.category }}</span>
                    <span v-else></span>
                    <ArrowUpRight class="size-4 text-muted-foreground/40 transition group-hover:text-orange-500" />
                </div>
                <h3 class="mt-3 text-lg font-bold leading-snug">{{ job.title }}</h3>
                <p class="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || 'Location N/A' }}
                </p>
                <div class="mt-4 flex items-center justify-between border-t pt-3 text-sm">
                    <span class="inline-flex items-center gap-0.5 font-bold text-rose-500 dark:text-rose-400"><IndianRupee class="size-3.5" />{{ wage(job) }}</span>
                    <span class="text-xs text-muted-foreground">{{ job.employer.name }}</span>
                </div>
            </Link>
        </div>

        <div v-else class="rounded-2xl border border-dashed bg-card px-5 py-16 text-center">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><Briefcase class="size-7" /></div>
            <p class="mt-4 font-medium">No jobs found</p>
            <p class="mt-1 text-sm text-muted-foreground">Try a different search or location.</p>
        </div>

        <!-- Pagination -->
        <div v-if="jobs.links.length > 3" class="flex flex-wrap justify-center gap-1">
            <Link
                v-for="(link, i) in jobs.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm transition',
                    link.active ? 'bg-gradient-to-r from-orange-500 to-rose-600 text-white' : 'text-muted-foreground hover:bg-muted',
                    !link.url ? 'pointer-events-none opacity-40' : '',
                ]"
                v-html="link.label"
            />
        </div>
    </div>
</template>
