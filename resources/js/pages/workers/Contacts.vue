<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, KeyRound, Lock, Mail, MapPin, MessageCircle, Phone, Search, UserRound, UsersRound } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import WorkerDatabaseTabs from '@/components/WorkerDatabaseTabs.vue';
import { citiesFor, indianStates } from '@/data/indianLocations';
import { commonSkills } from '@/data/skills';
import { wageText } from '@/lib/utils';

interface Contact {
    worker_id: number;
    profile_id: number | null;
    name: string;
    avatar_url: string | null;
    phone: string | null;
    email: string | null;
    city: string | null;
    state: string | null;
    skills: string[];
    experience_years: number | null;
    expected_wage: string | null;
    wage_type: string | null;
    // Database contacts
    unlocked_at?: string | null;
    unlocked_by?: string | null;
    // Applicant contacts
    application_id?: number;
    job?: { id: number; title: string } | null;
    stage?: string;
    applied_at?: string | null;
}

interface Filters {
    q?: string;
    skill?: string;
    state?: string;
    city?: string;
    period?: string;
    job?: number | string;
    stage?: string;
    sort?: string;
}

const props = defineProps<{
    tab: 'database' | 'applicants';
    contacts: { data: Contact[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: Filters;
    usage: {
        plan: string | null;
        database_plan: string | null;
        limit: number;
        used: number;
        remaining: number | null;
        resets_at: string | null;
        // One entry per plan held: job and/or database, each on its own cycle.
        pools: Record<string, { plan: string; limit: number; used: number; remaining: number | null; resets_at: string | null }>;
        used_database: number;
        used_applicants: number;
        has_database_access: boolean;
        has_job_plan: boolean;
        job_plan_lapsed: boolean;
        database_total: number;
        applicants_total: number;
        database_hidden: number;
        applicants_hidden: number;
    };
    jobs: { id: number; title: string }[];
    stages: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Worker Database', href: '/employer/workers' },
            { title: 'My contacts', href: '/employer/workers/contacts' },
        ],
    },
});

const path = computed(() => (props.tab === 'database' ? '/employer/workers/contacts' : '/employer/workers/contacts/applicants'));

const form = reactive({
    q: props.filters.q ?? '',
    skill: props.filters.skill ?? '',
    state: props.filters.state ?? '',
    city: props.filters.city ?? '',
    period: props.filters.period ?? 'all',
    job: props.filters.job ? String(props.filters.job) : '',
    stage: props.filters.stage ?? 'all',
    sort: props.filters.sort ?? 'recent',
});

const cities = computed(() => citiesFor(form.state));

const submit = () => {
    const params = Object.fromEntries(
        Object.entries(form).filter(([key, value]) => {
            if (value === '') return false;
            // Defaults stay out of the URL.
            if (key === 'period' && value === 'all') return false;
            if (key === 'stage' && value === 'all') return false;
            if (key === 'sort' && value === 'recent') return false;
            // Each list only takes its own filters.
            if (props.tab === 'database' && (key === 'job' || key === 'stage')) return false;
            if (props.tab === 'applicants' && key === 'period') return false;
            return true;
        }),
    );
    router.get(path.value, params, { preserveState: true, preserveScroll: true, replace: true });
};

watch(() => form.state, () => {
    if (form.city && !cities.value.includes(form.city)) form.city = '';
    submit();
});
watch(() => [form.city, form.period, form.job, form.stage, form.sort], submit);

const filtered = computed(() => ['q', 'skill', 'state', 'city', 'job'].some((k) => !!props.filters[k as keyof Filters])
    || (props.filters.period ?? 'all') !== 'all'
    || (props.filters.stage ?? 'all') !== 'all');

const go = (url: string | null) => {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const num = (n: number) => n.toLocaleString('en-IN');

const date = (iso: string | null | undefined) =>
    iso ? new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// wa.me wants the number with its country code and nothing else.
const whatsapp = (phone: string) => {
    const digits = phone.replace(/\D/g, '');
    return `https://wa.me/${digits.length === 10 ? '91' + digits : digits}`;
};

const stageLabel: Record<string, string> = {
    pending: 'New',
    shortlisted: 'Shortlisted',
    interview: 'Interview',
    hired: 'Hired',
    rejected: 'Rejected',
};

const stagePill: Record<string, string> = {
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
    shortlisted: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    interview: 'bg-sky-500/10 text-sky-600 ring-sky-500/20 dark:text-sky-300',
    hired: 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
};

// Contacts on this tab out of sight because the plan behind them ran out.
const hiddenHere = computed(() => (props.tab === 'database' ? props.usage.database_hidden : props.usage.applicants_hidden));

const field = 'rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40';
</script>

<template>
    <Head title="My contacts" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="UserRound" title="Worker Database" description="Browse worker contacts and call them directly to hire" />

        <WorkerDatabaseTabs :active="tab" :counts="{ database_total: usage.database_total, applicants_total: usage.applicants_total }" />

        <!-- Plan usage: each plan's unlocks, on its own cycle -->
        <div v-if="Object.keys(usage.pools).length" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-orange-500/5 px-5 py-4 text-sm">
            <div class="flex flex-col gap-1">
                <span v-for="(pool, key) in usage.pools" :key="key">
                    <strong>{{ pool.plan }}</strong>:
                    <template v-if="pool.limit > 0">
                        {{ num(pool.limit) }} contact unlocks per month, <strong>{{ num(pool.used) }}</strong> used, <strong>{{ num(pool.remaining ?? 0) }}</strong> left.
                    </template>
                    <template v-else>Unlimited contact unlocks.</template>
                    <span v-if="pool.resets_at" class="text-muted-foreground"> Renews {{ date(pool.resets_at) }}.</span>
                </span>
                <span class="text-muted-foreground">
                    This cycle: {{ num(usage.used_database) }} from the database, {{ num(usage.used_applicants) }} {{ usage.used_applicants === 1 ? 'applicant' : 'applicants' }}.
                </span>
            </div>
            <Link href="/subscription" class="shrink-0 text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">Need more? Upgrade →</Link>
        </div>
        <!-- No plan at all; when a plan ran out, the banner below says so instead. -->
        <div v-else-if="!hiddenHere" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
            <span class="inline-flex items-center gap-2"><KeyRound class="size-4 shrink-0" /> Subscribe to a job plan or a database plan to unlock karigar contacts.</span>
            <Link href="/subscription" class="shrink-0 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white">View plans</Link>
        </div>

        <!-- Contacts out of sight until the plan behind them is renewed -->
        <div
            v-if="hiddenHere"
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300"
        >
            <span class="inline-flex items-start gap-2">
                <Lock class="mt-0.5 size-4 shrink-0" />
                <span>
                    <template v-if="tab === 'database'">
                        Your Worker Database access has ended, so {{ num(hiddenHere) }} database {{ hiddenHere === 1 ? 'contact is' : 'contacts are' }} hidden. Renew a job or database plan to see them again.
                    </template>
                    <template v-else>
                        Your job plan has ended, so {{ num(hiddenHere) }} applicant {{ hiddenHere === 1 ? 'contact is' : 'contacts are' }} hidden. Renew it to see them again.
                    </template>
                    Karigars you shortlisted or hired stay visible.
                </span>
            </span>
            <Link href="/subscription" class="shrink-0 rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-white">Renew</Link>
        </div>

        <!-- Filters -->
        <form class="grid gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submit">
            <div class="relative sm:col-span-2">
                <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input v-model="form.q" placeholder="Name or phone…" :class="field" class="w-full pl-9" />
            </div>
            <input v-model="form.skill" list="contact-skill-options" placeholder="Skill" :class="field" @change="submit" />
            <datalist id="contact-skill-options">
                <option v-for="sk in commonSkills" :key="sk" :value="sk" />
            </datalist>
            <select v-model="form.sort" :class="field">
                <option value="recent">{{ tab === 'database' ? 'Recently unlocked' : 'Recently applied' }}</option>
                <option value="oldest">Oldest first</option>
                <option value="name">Name A–Z</option>
            </select>
            <select v-model="form.state" :class="field">
                <option value="">All states</option>
                <option v-for="st in indianStates" :key="st" :value="st">{{ st }}</option>
            </select>
            <select v-model="form.city" :disabled="!form.state" :class="field" class="disabled:opacity-50">
                <option value="">{{ form.state ? 'All cities' : 'Select state first' }}</option>
                <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
            </select>
            <template v-if="tab === 'database'">
                <select v-model="form.period" :class="field">
                    <option value="all">Unlocked any time</option>
                    <option value="cycle">Unlocked this billing cycle</option>
                </select>
            </template>
            <template v-else>
                <select v-model="form.job" :class="field">
                    <option value="">All jobs</option>
                    <option v-for="j in jobs" :key="j.id" :value="String(j.id)">{{ j.title }}</option>
                </select>
                <select v-model="form.stage" :class="field">
                    <option value="all">All stages</option>
                    <option v-for="s in stages" :key="s" :value="s">{{ stageLabel[s] ?? s }}</option>
                </select>
            </template>
            <div class="flex gap-2" :class="tab === 'database' ? '' : 'lg:col-span-4 lg:justify-end'">
                <button type="submit" class="flex-1 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white lg:flex-none">Go</button>
                <Link v-if="filtered" :href="path" class="rounded-xl border px-4 py-2.5 text-sm font-medium text-muted-foreground transition hover:bg-muted">Clear</Link>
            </div>
        </form>

        <p v-if="contacts.data.length" class="-mb-3 text-sm text-muted-foreground">
            {{ num(contacts.total) }} {{ contacts.total === 1 ? 'contact' : 'contacts' }}
        </p>

        <!-- List -->
        <div v-if="contacts.data.length" class="divide-y rounded-2xl border bg-card shadow-sm">
            <div
                v-for="c in contacts.data"
                :key="c.application_id ?? c.worker_id"
                class="flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-4"
            >
                <!-- Who -->
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <img v-if="c.avatar_url" :src="c.avatar_url" alt="" class="size-11 shrink-0 rounded-full object-cover" />
                    <div v-else class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary text-white"><UserRound class="size-5" /></div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold">{{ c.name }}</span>
                            <span
                                v-if="c.stage"
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                :class="stagePill[c.stage]"
                            >{{ stageLabel[c.stage] ?? c.stage }}</span>
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-1"><MapPin class="size-3" /> {{ [c.city, c.state].filter(Boolean).join(', ') || '—' }}</span>
                            <span v-if="c.experience_years != null">{{ c.experience_years }} yrs exp</span>
                            <span v-if="c.expected_wage">{{ wageText(c.expected_wage, null, c.wage_type) }}</span>
                        </div>
                        <div v-if="c.skills.length" class="mt-2 flex flex-wrap gap-1.5">
                            <span v-for="s in c.skills.slice(0, 4)" :key="s" class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">{{ s }}</span>
                        </div>
                        <!-- Where the contact came from -->
                        <div class="mt-2 text-xs text-muted-foreground">
                            <template v-if="tab === 'database'">
                                Unlocked {{ date(c.unlocked_at) }}<template v-if="c.unlocked_by"> by {{ c.unlocked_by }}</template>
                            </template>
                            <template v-else-if="c.job">
                                <span class="inline-flex items-center gap-1 font-medium text-foreground"><BriefcaseBusiness class="size-3" /> {{ c.job.title }}</span>
                                · Applied {{ date(c.applied_at) }}
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Reach -->
                <div class="flex flex-wrap items-center gap-2 md:shrink-0 md:justify-end">
                    <a
                        v-if="c.phone"
                        :href="`tel:${c.phone}`"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    >
                        <Phone class="size-4" /> {{ c.phone }}
                    </a>
                    <span v-else class="text-xs text-muted-foreground">No phone on file</span>
                    <a
                        v-if="c.phone"
                        :href="whatsapp(c.phone)"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center justify-center rounded-xl border px-3 py-2 text-muted-foreground transition hover:bg-muted"
                        title="WhatsApp"
                    >
                        <MessageCircle class="size-4" />
                    </a>
                    <a v-if="c.email" :href="`mailto:${c.email}`" class="inline-flex items-center justify-center rounded-xl border px-3 py-2 text-muted-foreground transition hover:bg-muted" title="Email">
                        <Mail class="size-4" />
                    </a>
                    <Link
                        v-if="tab === 'applicants' && c.job"
                        :href="`/employer/jobs/${c.job.id}/applicants`"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-medium text-muted-foreground transition hover:bg-muted"
                    >
                        <UsersRound class="size-3.5" /> Applicants
                    </Link>
                    <Link
                        v-else-if="c.profile_id"
                        :href="`/employer/workers/${c.profile_id}`"
                        class="inline-flex items-center justify-center rounded-xl border px-3 py-2 text-xs font-medium text-muted-foreground transition hover:bg-muted"
                    >View</Link>
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                <component :is="tab === 'database' ? KeyRound : BriefcaseBusiness" class="size-7" />
            </div>
            <template v-if="filtered">
                <p class="mt-4 font-medium">No contacts match these filters</p>
                <Link :href="path" class="mt-2 inline-block text-sm font-semibold text-orange-600 hover:underline dark:text-orange-400">Clear filters</Link>
            </template>
            <template v-else-if="tab === 'database'">
                <p class="mt-4 font-medium">No database contacts yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Karigars you unlock in Find karigars show up here, with their numbers.</p>
                <Link href="/employer/workers" class="mt-4 inline-flex rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white">Find karigars</Link>
            </template>
            <template v-else>
                <p class="mt-4 font-medium">No applicant contacts yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Applicants whose contact you unlock on your jobs show up here.</p>
                <Link href="/employer/jobs" class="mt-4 inline-flex rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white">Go to my jobs</Link>
            </template>
        </div>

        <!-- Pagination -->
        <div v-if="contacts.data.length && contacts.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
            <button
                v-for="link in contacts.links"
                :key="link.label"
                :disabled="!link.url"
                class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                :class="link.active ? 'border-orange-500 bg-orange-500/10 font-semibold text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                @click="go(link.url)"
                v-html="link.label"
            />
        </div>
    </div>
</template>
