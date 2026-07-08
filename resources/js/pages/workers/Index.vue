<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock, Mail, MapPin, Phone, Search, Star, UserRound } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Worker {
    id: number;
    user_id: number;
    name: string | null;
    avatar_url: string | null;
    bio: string | null;
    skills: string[];
    city: string | null;
    state: string | null;
    experience_years: number | null;
    expected_wage: string | null;
    wage_type: string | null;
    rating: number;
    phone: string | null;
    email: string | null;
    locked: boolean;
}

const props = defineProps<{
    workers: { data: Worker[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; state?: string; city?: string; skill?: string };
    access: { quota: number; accessible: number; total: number; has_plan: boolean };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Worker Database', href: '/employer/workers' }] } });

const form = reactive({
    q: props.filters.q ?? '',
    city: props.filters.city ?? '',
    state: props.filters.state ?? '',
    skill: props.filters.skill ?? '',
});

const submit = () => {
    router.get('/employer/workers', { ...form }, { preserveState: true, replace: true });
};

const go = (url: string | null) => {
    if (url) router.get(url, {}, { preserveState: true, preserveScroll: true });
};

const num = (n: number) => n.toLocaleString('en-IN');
</script>

<template>
    <Head title="Worker Database" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="UserRound" title="Worker Database" description="Browse worker contacts and call them directly to hire" />

        <!-- Access banner -->
        <div v-if="!access.has_plan" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/40 bg-amber-500/10 p-4 text-sm text-amber-700 dark:text-amber-300">
            <span class="inline-flex items-center gap-2"><Lock class="size-4 shrink-0" /> Subscribe to a plan to unlock worker contact numbers.</span>
            <Link href="/subscription" class="shrink-0 rounded-lg bg-gradient-to-r from-teal-500 to-cyan-600 px-4 py-2 text-xs font-semibold text-white">View plans</Link>
        </div>
        <div v-else class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-teal-500/5 px-5 py-4 text-sm">
            <span>
                Your plan gives access to <strong>{{ num(access.quota) }}</strong> worker contacts.
                <span class="text-muted-foreground">Showing contacts for the first {{ num(access.quota) }} matches.</span>
            </span>
            <Link href="/subscription" class="shrink-0 text-xs font-semibold text-teal-600 hover:underline dark:text-teal-400">Need more? Upgrade →</Link>
        </div>

        <form class="grid gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="submit">
            <div class="relative lg:col-span-2">
                <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input v-model="form.q" placeholder="Skill, name…" class="w-full rounded-xl border bg-background py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
            </div>
            <input v-model="form.skill" placeholder="Skill" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
            <input v-model="form.city" placeholder="City" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
            <div class="flex gap-2">
                <input v-model="form.state" placeholder="State" class="w-full rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
                <button type="submit" class="shrink-0 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-4 py-2.5 text-sm font-semibold text-white">Go</button>
            </div>
        </form>

        <div v-if="workers.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="w in workers.data"
                :key="w.id"
                class="relative flex flex-col rounded-2xl border bg-card p-5 shadow-sm transition hover:shadow-md"
            >
                <div class="flex items-center gap-3">
                    <img v-if="w.avatar_url" :src="w.avatar_url" alt="" class="size-12 rounded-full object-cover" />
                    <div v-else class="flex size-12 items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-cyan-600 text-white"><UserRound class="size-6" /></div>
                    <div class="min-w-0">
                        <div class="font-semibold">{{ w.name }}</div>
                        <div class="inline-flex items-center gap-1 text-xs text-muted-foreground"><MapPin class="size-3" /> {{ [w.city, w.state].filter(Boolean).join(', ') || '—' }}</div>
                    </div>
                    <span v-if="w.rating > 0" class="ml-auto inline-flex items-center gap-0.5 text-sm text-amber-500"><Star class="size-3.5" fill="currentColor" /> {{ w.rating }}</span>
                </div>
                <p v-if="w.bio" class="mt-3 line-clamp-2 text-sm text-muted-foreground">{{ w.bio }}</p>
                <div v-if="w.skills.length" class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="s in w.skills.slice(0, 4)" :key="s" class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">{{ s }}</span>
                </div>
                <div class="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                    <span v-if="w.experience_years != null">{{ w.experience_years }} yrs exp</span>
                    <span v-if="w.expected_wage" class="font-medium text-foreground">₹{{ w.expected_wage }}{{ w.wage_type ? ' / ' + w.wage_type : '' }}</span>
                </div>

                <!-- Contact / actions -->
                <div class="mt-4 flex items-center gap-2 border-t pt-4">
                    <template v-if="!w.locked && w.phone">
                        <a
                            :href="`tel:${w.phone}`"
                            class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-3 py-2 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                        >
                            <Phone class="size-4" /> {{ w.phone }}
                        </a>
                        <a v-if="w.email" :href="`mailto:${w.email}`" class="inline-flex items-center justify-center rounded-xl border px-3 py-2 text-muted-foreground transition hover:bg-muted" title="Email">
                            <Mail class="size-4" />
                        </a>
                    </template>
                    <template v-else-if="!w.locked">
                        <span class="flex-1 text-center text-xs text-muted-foreground">No phone on file</span>
                    </template>
                    <template v-else>
                        <span class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-muted px-3 py-2 text-xs font-medium text-muted-foreground">
                            <Lock class="size-3.5" /> {{ access.has_plan ? 'Beyond your quota' : 'Locked' }}
                        </span>
                    </template>
                    <Link :href="`/employer/workers/${w.id}`" class="inline-flex items-center justify-center rounded-xl border px-3 py-2 text-xs font-medium text-muted-foreground transition hover:bg-muted">View</Link>
                </div>
            </div>
        </div>

        <div v-else class="rounded-2xl border bg-card px-5 py-16 text-center shadow-sm">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground"><UserRound class="size-7" /></div>
            <p class="mt-4 font-medium">No workers found</p>
            <p class="mt-1 text-sm text-muted-foreground">Try a different skill or location.</p>
        </div>

        <!-- Pagination -->
        <div v-if="workers.data.length && workers.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
            <button
                v-for="link in workers.links"
                :key="link.label"
                :disabled="!link.url"
                class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                :class="link.active ? 'border-teal-500 bg-teal-500/10 font-semibold text-teal-600 dark:text-teal-300' : 'hover:bg-muted'"
                @click="go(link.url)"
                v-html="link.label"
            />
        </div>
    </div>
</template>
