<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BriefcaseBusiness, Database, Search } from '@lucide/vue';

defineProps<{
    active: 'find' | 'database' | 'applicants';
    counts: { database_total: number; applicants_total: number };
}>();

const tabs = [
    { key: 'find', label: 'Find karigars', href: '/employer/workers', icon: Search, count: null },
    { key: 'database', label: 'Database contacts', href: '/employer/workers/contacts', icon: Database, count: 'database_total' },
    { key: 'applicants', label: 'Applicant contacts', href: '/employer/workers/contacts/applicants', icon: BriefcaseBusiness, count: 'applicants_total' },
] as const;
</script>

<template>
    <nav class="flex gap-1 overflow-x-auto rounded-2xl border bg-card p-1 shadow-sm" aria-label="Worker Database">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold transition sm:flex-1"
            :class="active === tab.key ? 'bg-primary text-white shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
            :aria-current="active === tab.key ? 'page' : undefined"
        >
            <component :is="tab.icon" class="size-4" />
            {{ tab.label }}
            <span
                v-if="tab.count"
                class="rounded-full px-2 py-0.5 text-xs tabular-nums"
                :class="active === tab.key ? 'bg-white/20 text-white' : 'bg-muted text-muted-foreground'"
            >{{ counts[tab.count].toLocaleString('en-IN') }}</span>
        </Link>
    </nav>
</template>
