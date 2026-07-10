<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, MapPin, Search, X } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { citiesFor, indianStates } from '@/data/indianLocations';

interface Employer {
    id: number;
    name: string;
    company: string | null;
    phone: string | null;
    email: string;
    city: string | null;
    state: string | null;
    jobs: number;
    plan: string | null;
    kyc: string | null;
    suspended: boolean;
    joined: string;
}

const props = defineProps<{
    employers: { data: Employer[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; state?: string; city?: string; status?: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Employers', href: '/admin/employers' }] } });

const form = reactive({
    q: props.filters.q ?? '',
    state: props.filters.state ?? '',
    city: props.filters.city ?? '',
    status: props.filters.status ?? '',
});

const cities = computed(() => citiesFor(form.state));

let timer: ReturnType<typeof setTimeout> | undefined;
const run = () => {
    const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
    router.get('/admin/employers', params, { preserveState: true, preserveScroll: true, replace: true });
};
watch(
    () => ({ ...form }),
    (val, old) => {
        if (val.state !== old.state && form.city && !cities.value.includes(form.city)) form.city = '';
        clearTimeout(timer);
        timer = setTimeout(run, 350);
    },
);

const clearAll = () => {
    form.q = form.state = form.city = form.status = '';
};

const kycPill: Record<string, string> = {
    verified: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
    pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
    rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-300',
};

const go = (url: string | null) => url && router.get(url, {}, { preserveState: true, preserveScroll: true });
</script>

<template>
    <Head title="Employers" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Building2" title="Employers" description="All employer accounts with location filters" />

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card p-3 shadow-sm">
            <div class="flex min-w-52 flex-1 items-center gap-2 rounded-xl bg-accent px-3">
                <Search class="size-4 text-accent-foreground/70" />
                <input v-model="form.q" placeholder="Name, company, phone, email…" class="flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted-foreground" />
            </div>
            <select v-model="form.state" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30">
                <option value="">All states</option>
                <option v-for="st in indianStates" :key="st" :value="st">{{ st }}</option>
            </select>
            <select v-model="form.city" :disabled="!form.state" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30 disabled:opacity-50">
                <option value="">All cities</option>
                <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
            </select>
            <select v-model="form.status" class="h-9 rounded-xl border bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/30">
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
            <button v-if="form.q || form.state || form.city || form.status" class="inline-flex h-9 items-center gap-1 rounded-xl border px-3 text-sm text-muted-foreground hover:bg-muted" @click="clearAll">
                <X class="size-4" /> Clear
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-5 py-3 font-medium">Employer</th>
                            <th class="px-5 py-3 font-medium">Location</th>
                            <th class="px-5 py-3 font-medium">Jobs</th>
                            <th class="px-5 py-3 font-medium">Plan</th>
                            <th class="px-5 py-3 font-medium">KYC</th>
                            <th class="px-5 py-3 font-medium">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in employers.data" :key="e.id" class="border-t transition hover:bg-muted/30">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2 font-medium">
                                    {{ e.company || e.name }}
                                    <span v-if="e.suspended" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-[10px] font-semibold text-rose-600 dark:text-rose-300">Suspended</span>
                                </div>
                                <div class="text-xs text-muted-foreground">{{ e.phone ? `+91 ${e.phone}` : e.email }}</div>
                            </td>
                            <td class="px-5 py-3.5 text-muted-foreground">
                                <span class="inline-flex items-center gap-1"><MapPin class="size-3.5" /> {{ [e.city, e.state].filter(Boolean).join(', ') || '—' }}</span>
                            </td>
                            <td class="px-5 py-3.5 font-medium tabular-nums">{{ e.jobs }}</td>
                            <td class="px-5 py-3.5">
                                <span v-if="e.plan" class="rounded-full bg-orange-500/10 px-2.5 py-0.5 text-xs font-semibold text-orange-600 dark:text-orange-300">{{ e.plan }}</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span v-if="e.kyc" class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="kycPill[e.kyc] ?? 'bg-muted text-muted-foreground'">{{ e.kyc }}</span>
                                <span v-else class="text-xs text-muted-foreground">Not submitted</span>
                            </td>
                            <td class="px-5 py-3.5 whitespace-nowrap text-muted-foreground">{{ e.joined }}</td>
                        </tr>
                        <tr v-if="employers.data.length === 0">
                            <td colspan="6" class="px-5 py-16 text-center text-muted-foreground">No employers match these filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="employers.links.length > 3" class="flex flex-wrap justify-center gap-1">
            <button
                v-for="(link, i) in employers.links"
                :key="i"
                :disabled="!link.url"
                class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                :class="link.active ? 'border-orange-500 bg-orange-500/10 font-semibold text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                @click="go(link.url)"
                v-html="link.label"
            />
        </div>
    </div>
</template>
