<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, MapPin, Search, SlidersHorizontal, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PublicNav from '@/components/PublicNav.vue';
import { citiesFor, indianStates } from '@/data/indianLocations';
import { commonSkills } from '@/data/skills';

const { t } = useI18n();

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

const page = usePage();
const categories = computed(() => (page.props.categories as string[] | undefined) ?? []);

const filters = reactive({
    q: (props.filters.q as string) ?? '',
    category: (props.filters.category as string) ?? '',
    skill: (props.filters.skill as string) ?? '',
    state: (props.filters.state as string) ?? '',
    city: (props.filters.city as string) ?? '',
});

const cities = computed(() => citiesFor(filters.state));
watch(() => filters.state, () => {
    if (filters.city && !cities.value.includes(filters.city)) filters.city = '';
});

const searching = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const run = () => {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null));
    searching.value = true;
    router.get('/jobs', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['jobs', 'filters'],
        onFinish: () => (searching.value = false),
    });
};

const debouncedRun = () => {
    clearTimeout(timer);
    timer = setTimeout(run, 300);
};

watch(() => filters.q, (val) => {
    // Search as you type from 2 characters onward, or when cleared.
    if (val.length === 0 || val.length >= 2) debouncedRun();
});
watch([() => filters.category, () => filters.skill, () => filters.state, () => filters.city], run);

const hasFilters = computed(() => Object.values(filters).some((v) => v !== ''));
const clearAll = () => {
    filters.q = filters.category = filters.skill = filters.state = filters.city = '';
    run();
};

const wage = (j: Job) => {
    if (!j.wage_min && !j.wage_max) return t('jobs.negotiable');
    const range = [j.wage_min, j.wage_max].filter(Boolean).join('–');
    return `₹${range}${j.wage_type ? ' / ' + j.wage_type : ''}`;
};

// Ruled fields, matching the landing's search: a bottom border, no box.
const inputClass =
    'w-full border-b border-foreground/20 bg-transparent py-2 text-sm outline-none transition placeholder:text-muted-foreground/70 focus:border-primary';
const selectClass = inputClass;
</script>

<template>
    <Head :title="t('jobs.browseTitle')" />

    <div class="theme-paper bg-noise min-h-screen bg-background text-foreground antialiased">
        <PublicNav>
            <Link href="/jobs" class="link-underline">{{ t('nav.browseJobs') }}</Link>
            <Link href="/worker/register" class="link-underline">{{ t('landing.forWorkers') }}</Link>
            <Link href="/employer/register" class="link-underline">{{ t('landing.forEmployers') }}</Link>
        </PublicNav>

        <main class="mx-auto max-w-[88rem] px-6 py-16 lg:px-10">
            <div class="flex items-center gap-3 text-primary">
                <span class="h-px w-10 bg-primary"></span>
                <span class="label-rule">{{ t('nav.browseJobs') }}</span>
            </div>
            <h1 class="display-lg mt-5 max-w-xl">{{ t('jobs.browseTitle') }}</h1>
            <p class="mt-4 max-w-lg text-muted-foreground">{{ t('jobs.browseSubtitle') }}</p>

            <!-- Filters: a ruled row, no card -->
            <div class="mt-12 border-t border-foreground/15 pt-6">
                <div class="flex items-center justify-between">
                    <div class="label-rule inline-flex items-center gap-2 text-muted-foreground">
                        <SlidersHorizontal class="size-3.5" /> {{ t('common.filter') }}
                    </div>
                    <button v-if="hasFilters" class="link-underline inline-flex items-center gap-1 text-xs font-medium text-muted-foreground" @click="clearAll">
                        <X class="size-3.5" /> {{ t('common.clear') }}
                    </button>
                </div>
                <div class="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="relative lg:col-span-1">
                        <Search class="absolute left-0 top-2.5 size-4 text-muted-foreground" :class="searching && 'animate-pulse text-primary'" />
                        <input v-model="filters.q" :placeholder="t('jobs.filters.search')" :class="inputClass" class="!pl-6" />
                    </div>
                    <select v-model="filters.category" :class="selectClass">
                        <option value="">{{ t('jobs.filters.category') }}</option>
                        <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                    </select>
                    <input v-model="filters.skill" list="skill-options" placeholder="Skill" :class="inputClass" />
                    <datalist id="skill-options">
                        <option v-for="s in commonSkills" :key="s" :value="s" />
                    </datalist>
                    <select v-model="filters.state" :class="selectClass">
                        <option value="">{{ t('jobs.filters.state') }}</option>
                        <option v-for="s in indianStates" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <select v-model="filters.city" :disabled="!filters.state" :class="selectClass" class="disabled:opacity-50">
                        <option value="">{{ filters.state ? t('jobs.filters.city') : '—' }}</option>
                        <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
            </div>

            <!-- Results: a listings index, one ruled row per job -->
            <div v-if="jobs.data.length" class="mt-12 border-t border-foreground/15">
                <Link
                    v-for="job in jobs.data"
                    :key="job.id"
                    :href="`/jobs/${job.id}`"
                    class="group grid grid-cols-1 items-baseline gap-x-6 gap-y-2 border-b border-foreground/10 py-7 transition hover:bg-foreground/[0.03] md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_13rem]"
                >
                    <div>
                        <h3 class="text-xl font-bold tracking-tight">
                            <span class="link-underline">{{ job.title }}</span>
                        </h3>
                        <p class="mt-1.5 text-[13px] text-muted-foreground">{{ t('jobs.by') }} {{ job.employer.name }}</p>
                    </div>
                    <div class="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                        <MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || t('jobs.locationNA') }}
                    </div>
                    <div class="text-sm font-semibold">{{ wage(job) }}</div>
                    <div class="flex items-center justify-end gap-4">
                        <span v-if="job.category" class="label-rule hidden min-w-0 truncate text-right text-muted-foreground lg:block">{{ job.category }}</span>
                        <ArrowUpRight class="size-5 shrink-0 text-muted-foreground transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-primary" />
                    </div>
                </Link>
            </div>

            <div v-else class="mt-12 border-y border-foreground/15 py-20 text-center text-muted-foreground">
                {{ t('jobs.noJobs') }}
            </div>

            <!-- Pagination -->
            <div v-if="jobs.links.length > 3" class="mt-10 flex flex-wrap justify-center gap-1">
                <Link
                    v-for="(link, i) in jobs.links"
                    :key="i"
                    :href="link.url ?? ''"
                    :class="[
                        'min-w-9 rounded-sm px-3 py-1.5 text-center text-sm transition',
                        link.active ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-foreground/5 hover:text-foreground',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    v-html="link.label"
                />
            </div>
        </main>
    </div>
</template>
