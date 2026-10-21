<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Layers, Plus, Trash2, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Plan {
    id: number;
    name: string;
    tagline: string | null;
    slug: string;
    type: 'job' | 'database';
    price: string;
    mrp: string | null;
    interval: string;
    interval_label?: string;
    duration_days: number | null;
    job_live_days: number | null;
    features: {
        job_post_limit?: number;
        contact_unlock_limit?: number;
        contact_database_limit?: number;
        featured?: boolean;
    } | null;
    is_active: boolean;
    subscriptions_count: number;
}

interface Draft {
    name: string;
    tagline: string;
    price: number;
    mrp: number | null;
    duration_days: number;
    job_live_days: number | null;
    job_post_limit: number;
    contact_unlock_limit: number;
    contact_database_limit: number;
    featured: boolean;
    is_active: boolean;
}

const props = defineProps<{ plans: Plan[]; gstPercent: number }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Plans', href: '/admin/plans' }] } });

const withGst = (price: number) => Math.round(price * (100 + props.gstPercent)) / 100;
const off = (d: Draft) => (d.mrp && d.mrp > d.price ? Math.round(((d.mrp - d.price) / d.mrp) * 100) : 0);

// Monthly / yearly plans from before keep their calendar period.
const calendarBased = (p: Plan) => p.interval === 'monthly' || p.interval === 'yearly';

const toDraft = (p: Plan): Draft => ({
    name: p.name,
    tagline: p.tagline ?? '',
    price: Number(p.price),
    mrp: p.mrp !== null ? Number(p.mrp) : null,
    duration_days: p.duration_days ?? 30,
    job_live_days: p.job_live_days,
    job_post_limit: p.features?.job_post_limit ?? 0,
    contact_unlock_limit: p.features?.contact_unlock_limit ?? 0,
    contact_database_limit: p.features?.contact_database_limit ?? 0,
    featured: p.features?.featured ?? false,
    is_active: p.is_active,
});

const drafts = reactive<Record<number, Draft>>({});
// Fresh rows after every save, add or delete.
watch(
    () => props.plans,
    (plans) => {
        for (const key of Object.keys(drafts)) delete drafts[Number(key)];
        for (const p of plans) drafts[p.id] = toDraft(p);
    },
    { immediate: true },
);

const payload = (d: Draft) => ({ ...d, tagline: d.tagline.trim() || null, mrp: d.mrp || null, job_live_days: d.job_live_days || null });

const save = (p: Plan) => {
    const d = drafts[p.id];
    router.patch(`/admin/plans/${p.id}`, { ...payload(d), ...(calendarBased(p) ? { duration_days: undefined } : {}) }, { preserveScroll: true });
};

// Two clicks: the first arms the button, the second deletes.
const confirming = ref<number | null>(null);
const remove = (p: Plan) => {
    if (confirming.value !== p.id) {
        confirming.value = p.id;
        return;
    }
    confirming.value = null;
    router.delete(`/admin/plans/${p.id}`, { preserveScroll: true });
};

const page = usePage();
const errors = computed(() => Object.values(page.props.errors as Record<string, string>));

// ── New plan ────────────────────────────────────────────────────────
const adding = ref(false);
const blank = (): Draft & { type: 'job' | 'database' } => ({
    type: 'job',
    name: '',
    tagline: '',
    price: 999,
    mrp: null,
    duration_days: 30,
    job_live_days: 15,
    job_post_limit: 3,
    contact_unlock_limit: 50,
    contact_database_limit: 3000,
    featured: false,
    is_active: true,
});
const fresh = reactive(blank());

const create = () => {
    router.post('/admin/plans', payload(fresh), {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(fresh, blank());
            adding.value = false;
        },
    });
};

const input = 'w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <Head title="Plans" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader
            :icon="Layers"
            title="Plans & Limits"
            description="Everything on the pricing page comes from here. A new price or length reaches Razorpay by itself on the next checkout; running subscriptions keep what they signed up for."
        >
            <template #action>
                <button
                    v-if="!adding"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90"
                    @click="adding = true"
                >
                    <Plus class="size-4" /> New plan
                </button>
            </template>
        </PageHeader>

        <ul v-if="errors.length" class="rounded-2xl border border-rose-500/30 bg-rose-500/10 px-5 py-3 text-sm text-rose-700 dark:text-rose-300">
            <li v-for="error in errors" :key="error">{{ error }}</li>
        </ul>

        <!-- New plan -->
        <div v-if="adding" class="rounded-2xl border border-orange-500/40 bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between border-b pb-3">
                <h3 class="font-semibold">New plan</h3>
                <button class="rounded-lg p-1.5 text-muted-foreground hover:bg-muted" @click="adding = false"><X class="size-4" /></button>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label :class="label">Type</label>
                    <select v-model="fresh.type" :class="input">
                        <option value="job">Job plan</option>
                        <option value="database">Database plan</option>
                    </select>
                </div>
                <div>
                    <label :class="label">Name</label>
                    <input v-model="fresh.name" :class="input" placeholder="1 Month" />
                </div>
                <div class="sm:col-span-2">
                    <label :class="label">Tagline (optional)</label>
                    <input v-model="fresh.tagline" :class="input" placeholder="Ideal for small teams" />
                </div>
                <div>
                    <label :class="label">Price ₹ (before GST)</label>
                    <input v-model.number="fresh.price" type="number" min="1" :class="input" />
                </div>
                <div>
                    <label :class="label">MRP ₹, struck out (optional)</label>
                    <input v-model.number="fresh.mrp" type="number" min="0" :class="input" />
                </div>
                <div>
                    <label :class="label">Plan runs for (days, min 7)</label>
                    <input v-model.number="fresh.duration_days" type="number" min="7" :class="input" />
                </div>
                <div v-if="fresh.type === 'job'">
                    <label :class="label">Each job live for (days)</label>
                    <input v-model.number="fresh.job_live_days" type="number" min="1" :class="input" />
                </div>
                <div v-if="fresh.type === 'job'">
                    <label :class="label">Job posts per plan period</label>
                    <input v-model.number="fresh.job_post_limit" type="number" min="0" :class="input" />
                </div>
                <div>
                    <label :class="label">Database unlocks (0 = unlimited)</label>
                    <input v-model.number="fresh.contact_unlock_limit" type="number" min="0" :class="input" />
                </div>
                <div>
                    <label :class="label">Karigar-database contacts</label>
                    <input v-model.number="fresh.contact_database_limit" type="number" min="0" :class="input" />
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="fresh.featured" type="checkbox" class="size-4 rounded border-input text-orange-600" /> Most popular badge</label>
                    <label class="flex items-center gap-2"><input v-model="fresh.is_active" type="checkbox" class="size-4 rounded border-input text-orange-600" /> Active</label>
                </div>
                <button
                    :disabled="!fresh.name.trim()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                    @click="create"
                >
                    <Plus class="size-4" /> Add plan
                </button>
            </div>
        </div>

        <!-- Existing plans -->
        <div v-for="p in plans" :key="p.id" class="rounded-2xl border bg-card p-5 shadow-sm" :class="drafts[p.id]?.is_active ? '' : 'opacity-75'">
            <template v-if="drafts[p.id]">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b pb-3">
                    <div>
                        <h3 class="flex flex-wrap items-center gap-2 font-semibold">
                            {{ p.name }}
                            <span
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="p.type === 'database' ? 'bg-sky-500/10 text-sky-600 dark:text-sky-300' : 'bg-muted text-muted-foreground'"
                            >{{ p.type === 'database' ? 'Database plan' : 'Job plan' }}</span>
                            <span v-if="p.subscriptions_count" class="text-[11px] font-normal text-muted-foreground">{{ p.subscriptions_count }} bought</span>
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            <span v-if="off(drafts[p.id])" class="mr-1 line-through">₹{{ drafts[p.id].mrp }}</span>
                            ₹{{ drafts[p.id].price }} / {{ calendarBased(p) ? p.interval_label : `${drafts[p.id].duration_days} days` }}
                            <span v-if="off(drafts[p.id])" class="ml-1 font-semibold text-emerald-600 dark:text-emerald-400">{{ off(drafts[p.id]) }}% OFF</span>
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

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label :class="label">Name</label>
                        <input v-model="drafts[p.id].name" :class="input" />
                    </div>
                    <div class="sm:col-span-1 lg:col-span-3">
                        <label :class="label">Tagline (optional)</label>
                        <input v-model="drafts[p.id].tagline" :class="input" placeholder="Ideal for small teams" />
                    </div>
                    <div>
                        <label :class="label">Price ₹ (before GST)</label>
                        <input v-model.number="drafts[p.id].price" type="number" min="1" step="1" :class="input" />
                    </div>
                    <div>
                        <label :class="label">MRP ₹, struck out (optional)</label>
                        <input v-model.number="drafts[p.id].mrp" type="number" min="0" step="1" :class="input" />
                    </div>
                    <div>
                        <label :class="label">Plan runs for (days, min 7)</label>
                        <input v-if="!calendarBased(p)" v-model.number="drafts[p.id].duration_days" type="number" min="7" :class="input" />
                        <p v-else class="py-2 text-sm text-muted-foreground">1 {{ p.interval_label }} (calendar)</p>
                    </div>
                    <!-- A database plan posts no jobs. -->
                    <div v-if="p.type !== 'database'">
                        <label :class="label">Each job live for (days)</label>
                        <input v-model.number="drafts[p.id].job_live_days" type="number" min="1" :class="input" placeholder="Whole plan" />
                    </div>
                    <div v-if="p.type !== 'database'">
                        <label :class="label">Job posts per plan period (0 = unlimited)</label>
                        <input v-model.number="drafts[p.id].job_post_limit" type="number" min="0" :class="input" />
                    </div>
                    <div>
                        <label :class="label">Database unlocks (0 = unlimited)</label>
                        <input v-model.number="drafts[p.id].contact_unlock_limit" type="number" min="0" :class="input" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-orange-600 dark:text-orange-400">Karigar-database contacts</label>
                        <input v-model.number="drafts[p.id].contact_database_limit" type="number" min="0" :class="input" />
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <div class="flex items-center gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="drafts[p.id].featured" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" /> Most popular badge</label>
                        <label class="flex items-center gap-2"><input v-model="drafts[p.id].is_active" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" /> Active</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <!-- A plan someone bought stays for its invoices; hide it instead. -->
                        <button
                            v-if="!p.subscriptions_count"
                            class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-500/10"
                            @click="remove(p)"
                        >
                            <Trash2 class="size-4" /> {{ confirming === p.id ? 'Sure? Delete' : 'Delete' }}
                        </button>
                        <button
                            class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                            @click="save(p)"
                        >
                            <Check class="size-4" /> Save
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>
