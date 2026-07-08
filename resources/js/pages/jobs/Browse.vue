<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowUpRight, MapPin, Search, SlidersHorizontal, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';
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

const inputClass =
    'w-full rounded-xl border border-white/10 bg-white/[0.04] px-3 py-2 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-orange-400/50 focus:bg-white/[0.06]';
const selectClass = inputClass + ' [&>option]:bg-[#141a33]';
</script>

<template>
    <Head :title="t('jobs.browseTitle')" />

    <div class="dark relative min-h-screen overflow-hidden bg-[#0a0e21] text-slate-200 antialiased">
        <div class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute left-1/2 top-[-10%] h-[420px] w-[680px] -translate-x-1/2 rounded-full bg-orange-600/20 blur-[140px]"></div>
            <div class="absolute right-[-5%] top-[25%] h-[320px] w-[320px] rounded-full bg-rose-600/15 blur-[120px]"></div>
        </div>

        <!-- Nav -->
        <header class="sticky top-0 z-30 border-b border-white/5 bg-[#0a0e21]/70 backdrop-blur-xl">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-5 py-3.5">
                <Link href="/" class="flex items-center gap-2.5 text-lg font-bold text-white">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-lg shadow-orange-500/40">K</span>
                    Karigar
                </Link>
                <div class="flex items-center gap-2">
                    <LanguageSwitcher />
                    <Link href="/login" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/10">
                        {{ t('common.login') }}
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-5 py-10">
            <div class="mb-8">
                <h1 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">{{ t('jobs.browseTitle') }}</h1>
                <p class="mt-2 text-slate-400">{{ t('jobs.browseSubtitle') }}</p>
            </div>

            <!-- Filters -->
            <div class="mb-8 rounded-2xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur">
                <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-orange-300">
                    <SlidersHorizontal class="size-4" /> {{ t('common.filter') }}
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="lg:col-span-2">
                        <div class="relative">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-500" :class="searching && 'animate-pulse text-orange-400'" />
                            <input v-model="filters.q" :placeholder="t('jobs.filters.search')" :class="inputClass" class="!pl-9" />
                        </div>
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
                <div v-if="hasFilters" class="mt-3 flex justify-end">
                    <button class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition hover:text-white" @click="clearAll">
                        <X class="size-3.5" /> Clear
                    </button>
                </div>
            </div>

            <!-- Results -->
            <div v-if="jobs.data.length" class="grid gap-4 sm:grid-cols-2">
                <Link
                    v-for="job in jobs.data"
                    :key="job.id"
                    :href="`/jobs/${job.id}`"
                    class="group relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.03] p-5 transition duration-300 hover:-translate-y-1 hover:border-orange-400/40 hover:bg-white/[0.05]"
                >
                    <div class="flex items-center justify-between">
                        <span v-if="job.category" class="rounded-full border border-orange-400/20 bg-orange-500/10 px-2.5 py-0.5 text-xs font-semibold text-orange-300">{{ job.category }}</span>
                        <span v-else></span>
                        <ArrowUpRight class="size-4 text-slate-600 transition group-hover:text-orange-400" />
                    </div>
                    <h3 class="mt-3 text-lg font-bold leading-snug text-white">{{ job.title }}</h3>
                    <p class="mt-1 inline-flex items-center gap-1 text-xs text-slate-500">
                        <MapPin class="size-3.5" /> {{ [job.city, job.state].filter(Boolean).join(', ') || t('jobs.locationNA') }}
                    </p>
                    <div class="mt-4 flex items-center justify-between border-t border-white/5 pt-3 text-sm">
                        <span class="font-bold text-rose-300">{{ wage(job) }}</span>
                        <span class="text-xs text-slate-500">{{ t('jobs.by') }} {{ job.employer.name }}</span>
                    </div>
                </Link>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-white/10 p-16 text-center text-slate-500">
                {{ t('jobs.noJobs') }}
            </div>

            <!-- Pagination -->
            <div v-if="jobs.links.length > 3" class="mt-8 flex flex-wrap justify-center gap-1">
                <Link
                    v-for="(link, i) in jobs.links"
                    :key="i"
                    :href="link.url ?? ''"
                    :class="[
                        'min-w-9 rounded-lg border border-white/10 px-3 py-1.5 text-center text-sm transition',
                        link.active ? 'bg-gradient-to-r from-orange-500 to-rose-600 text-white' : 'text-slate-400 hover:bg-white/5',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    v-html="link.label"
                />
            </div>
        </main>
    </div>
</template>
