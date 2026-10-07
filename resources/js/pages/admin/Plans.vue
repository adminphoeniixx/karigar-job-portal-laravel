<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, Layers } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Plan {
    id: number;
    name: string;
    slug: string;
    type: 'job' | 'database';
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

const props = defineProps<{ plans: Plan[]; gstPercent: number }>();

const withGst = (price: number) => Math.round(price * (100 + props.gstPercent)) / 100;

defineOptions({ layout: { breadcrumbs: [{ title: 'Plans', href: '/admin/plans' }] } });

const drafts = reactive<
    Record<
        number,
        { price: number; job_post_limit: number; contact_unlock_limit: number; contact_database_limit: number; featured: boolean; is_active: boolean }
    >
>(
    Object.fromEntries(
        props.plans.map((p) => [
            p.id,
            {
                price: Number(p.price),
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

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Layers" title="Plans & Limits" description="Price and what each plan grants. A new price reaches Razorpay by itself on the next checkout; running subscriptions keep the price they signed up at." />

        <div v-for="p in plans" :key="p.id" class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between border-b pb-3">
                <div>
                    <h3 class="flex items-center gap-2 font-semibold">
                        {{ p.name }}
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                            :class="p.type === 'database' ? 'bg-sky-500/10 text-sky-600 dark:text-sky-300' : 'bg-muted text-muted-foreground'"
                        >{{ p.type === 'database' ? 'Database plan' : 'Job plan' }}</span>
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        ₹{{ drafts[p.id].price }} / {{ p.interval }}
                        <template v-if="gstPercent > 0"> · ₹{{ withGst(drafts[p.id].price) }} with {{ gstPercent }}% GST</template>
                    </p>
                </div>
                <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                    :class="drafts[p.id].is_active ? 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-300' : 'bg-muted text-muted-foreground ring-border'"
                >
                    {{ drafts[p.id].is_active ? 'Active' : 'Hidden' }}
                </span>
            </div>

            <div class="mt-4 grid gap-4" :class="p.type === 'database' ? 'sm:grid-cols-3' : 'sm:grid-cols-4'">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Price ₹ (before GST)</label>
                    <input v-model.number="drafts[p.id].price" type="number" min="1" step="1" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <!-- A database plan posts no jobs. -->
                <div v-if="p.type !== 'database'">
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Job posts / {{ p.interval === 'yearly' ? 'year' : 'month' }} (0 = unlimited)</label>
                    <input v-model.number="drafts[p.id].job_post_limit" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Contact unlocks (0 = unlimited)</label>
                    <input v-model.number="drafts[p.id].contact_unlock_limit" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-orange-600 dark:text-orange-400">Karigar-database contacts</label>
                    <input v-model.number="drafts[p.id].contact_database_limit" type="number" min="0" class="w-full rounded-xl border border-orange-500/30 bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="drafts[p.id].featured" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" /> Recommended badge</label>
                    <label class="flex items-center gap-2"><input v-model="drafts[p.id].is_active" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" /> Active</label>
                </div>
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    @click="save(p)"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </div>
    </div>
</template>
