<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Layers } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: string;
    interval: string;
    features: {
        job_post_limit?: number;
        contact_unlock_limit?: number;
        contact_database_limit?: number;
        featured?: boolean;
    } | null;
    is_active: boolean;
}

const props = defineProps<{ plans: Plan[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Plans', href: '/admin/plans' }] } });

const drafts = reactive<
    Record<
        number,
        { job_post_limit: number; contact_unlock_limit: number; contact_database_limit: number; featured: boolean; is_active: boolean }
    >
>(
    Object.fromEntries(
        props.plans.map((p) => [
            p.id,
            {
                job_post_limit: p.features?.job_post_limit ?? 0,
                contact_unlock_limit: p.features?.contact_unlock_limit ?? 0,
                contact_database_limit: p.features?.contact_database_limit ?? 0,
                featured: p.features?.featured ?? false,
                is_active: p.is_active,
            },
        ]),
    ),
);

const save = (p: Plan) => {
    router.patch(`/admin/plans/${p.id}`, drafts[p.id], { preserveScroll: true });
};
</script>

<template>
    <Head title="Plans" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="Layers" title="Plans & Limits" description="Set what each subscription plan grants — including how many worker-database contacts an employer can access." />

        <div v-for="p in plans" :key="p.id" class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between border-b pb-3">
                <div>
                    <h3 class="font-semibold">{{ p.name }}</h3>
                    <p class="text-xs text-muted-foreground">₹{{ p.price }} / {{ p.interval }}</p>
                </div>
                <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                    :class="drafts[p.id].is_active ? 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-300' : 'bg-muted text-muted-foreground ring-border'"
                >
                    {{ drafts[p.id].is_active ? 'Active' : 'Hidden' }}
                </span>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Job posts</label>
                    <input v-model.number="drafts[p.id].job_post_limit" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Contact unlocks (applications)</label>
                    <input v-model.number="drafts[p.id].contact_unlock_limit" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-teal-600 dark:text-teal-400">Worker-database contacts</label>
                    <input v-model.number="drafts[p.id].contact_database_limit" type="number" min="0" class="w-full rounded-xl border border-teal-500/30 bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500/40" />
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="drafts[p.id].featured" type="checkbox" class="size-4 rounded border-input text-teal-600 focus:ring-teal-500/40" /> Featured</label>
                    <label class="flex items-center gap-2"><input v-model="drafts[p.id].is_active" type="checkbox" class="size-4 rounded border-input text-teal-600 focus:ring-teal-500/40" /> Active</label>
                </div>
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    @click="save(p)"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </div>
    </div>
</template>
