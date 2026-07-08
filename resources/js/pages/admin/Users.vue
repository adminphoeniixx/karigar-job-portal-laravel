<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Users as UsersIcon, Search, Ban, Database, RotateCcw } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Row {
    id: number;
    name: string;
    email: string;
    role: string;
    suspended: boolean;
    created_at: string;
    is_employer: boolean;
    quota_bonus: number | null;
    quota_total: number | null;
}

const props = defineProps<{
    users: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; role?: string; status?: string };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Users', href: '/admin/users' }] } });

const q = ref(props.filters.q ?? '');
const role = ref(props.filters.role ?? '');
const status = ref(props.filters.status ?? '');

let timer: ReturnType<typeof setTimeout>;
watch([q, role, status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/users', { q: q.value || undefined, role: role.value || undefined, status: status.value || undefined }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

const suspend = (u: Row) => {
    if (window.confirm(`Suspend ${u.name}? They will be logged out and blocked.`)) {
        router.post(`/admin/users/${u.id}/suspend`, {}, { preserveScroll: true });
    }
};
const reinstate = (u: Row) => router.post(`/admin/users/${u.id}/unsuspend`, {}, { preserveScroll: true });

// Per-employer bonus quota editing.
const bonuses = reactive<Record<number, number>>({});
const saveQuota = (u: Row) => {
    const val = bonuses[u.id] ?? u.quota_bonus ?? 0;
    router.post(`/admin/users/${u.id}/quota`, { contact_quota_bonus: val }, { preserveScroll: true });
};

const roleBadge: Record<string, string> = {
    worker: 'bg-teal-500/10 text-teal-600 dark:text-teal-300',
    employer: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-300',
    admin: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
};
</script>

<template>
    <Head title="Users" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="UsersIcon" title="User Management" description="Search, suspend, or reinstate accounts" />

        <!-- Filters -->
        <div class="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm sm:flex-row">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <input v-model="q" placeholder="Search name or email…" class="w-full rounded-xl border bg-background py-2.5 pl-9 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
            </div>
            <select v-model="role" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40">
                <option value="">All roles</option>
                <option value="worker">Worker</option>
                <option value="employer">Employer</option>
                <option value="admin">Admin</option>
            </select>
            <select v-model="status" class="rounded-xl border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-for="u in users.data" :key="u.id" class="flex items-center justify-between gap-3 border-b px-5 py-3.5 last:border-0">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="truncate font-medium">{{ u.name }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize" :class="roleBadge[u.role]">{{ u.role }}</span>
                        <span v-if="u.suspended" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600 dark:text-rose-400">Suspended</span>
                    </div>
                    <div class="truncate text-xs text-muted-foreground">{{ u.email }} · joined {{ u.created_at }}</div>
                    <!-- Employer worker-database quota -->
                    <div v-if="u.is_employer" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                        <span class="inline-flex items-center gap-1 text-muted-foreground"><Database class="size-3.5" /> DB access: <strong class="text-foreground">{{ (u.quota_total ?? 0).toLocaleString('en-IN') }}</strong> contacts</span>
                        <span class="text-muted-foreground">Bonus:</span>
                        <input
                            type="number"
                            min="0"
                            :value="bonuses[u.id] ?? u.quota_bonus ?? 0"
                            class="w-24 rounded-lg border bg-background px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-teal-500/40"
                            @input="bonuses[u.id] = Number(($event.target as HTMLInputElement).value)"
                        />
                        <button class="rounded-lg bg-teal-500/10 px-2.5 py-1 font-medium text-teal-600 transition hover:bg-teal-500/20 dark:text-teal-300" @click="saveQuota(u)">Save</button>
                    </div>
                </div>
                <div class="shrink-0">
                    <button
                        v-if="!u.suspended && u.role !== 'admin'"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-500/10 dark:text-rose-400"
                        @click="suspend(u)"
                    >
                        <Ban class="size-3.5" /> Suspend
                    </button>
                    <button
                        v-else-if="u.suspended"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-teal-600 transition hover:bg-teal-500/10 dark:text-teal-400"
                        @click="reinstate(u)"
                    >
                        <RotateCcw class="size-3.5" /> Reinstate
                    </button>
                </div>
            </div>
            <div v-if="users.data.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">No users match these filters.</div>
        </div>

        <!-- Pagination -->
        <div v-if="users.links.length > 3" class="flex flex-wrap justify-center gap-1">
            <Link
                v-for="(l, i) in users.links"
                :key="i"
                :href="l.url ?? ''"
                preserve-scroll
                class="rounded-lg px-3 py-1.5 text-sm transition"
                :class="[l.active ? 'bg-teal-500 text-white' : l.url ? 'border hover:bg-muted' : 'cursor-default text-muted-foreground opacity-50']"
                v-html="l.label"
            />
        </div>
    </div>
</template>
