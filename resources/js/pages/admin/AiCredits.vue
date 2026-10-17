<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Coins, FileText, Plus, Search, Trash2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';

interface Settings {
    enabled: boolean;
    per_minute: number;
    min_balance: number;
    low_alert: number;
    welcome: number;
    max_call_minutes: number;
}

interface Pack {
    id: number;
    name: string;
    price: number;
    price_with_gst: number;
    credits: number;
    bonus_credits: number;
    total_credits: number;
    minutes: number;
    popular: boolean;
    is_active: boolean;
    sort_order: number;
    sold: number;
}

interface Row {
    id: number;
    type: string;
    type_label: string;
    credits: number;
    balance_after: number;
    description: string;
    amount_paid: number | null;
    invoice: { number: string; web_url: string } | null;
    created_label: string | null;
    employer: { id: number | null; name: string | null; phone: string | null };
    by: string | null;
}

const props = defineProps<{
    settings: Settings;
    packs: Pack[];
    stats: { revenue: number; recharges: number; credits_spent: number; outstanding: number };
    ledger: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] };
    filters: { q?: string; type?: string };
    types: { value: string; label: string }[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AI Credits', href: '/admin/ai-credits' }] } });

const inr = (v: number) => '₹' + v.toLocaleString('en-IN', { maximumFractionDigits: 2 });
const num = (v: number) => v.toLocaleString('en-IN');

const settingsForm = useForm({
    enabled: props.settings.enabled,
    per_minute: props.settings.per_minute,
    min_balance: props.settings.min_balance,
    low_alert: props.settings.low_alert,
    welcome: props.settings.welcome,
});
const saveSettings = () => settingsForm.patch('/admin/ai-credits/settings', { preserveScroll: true });

const drafts = reactive<Record<number, { name: string; price: number; credits: number; bonus_credits: number; is_active: boolean; is_popular: boolean; sort_order: number }>>(
    Object.fromEntries(
        props.packs.map((p) => [
            p.id,
            { name: p.name, price: p.price, credits: p.credits, bonus_credits: p.bonus_credits, is_active: p.is_active, is_popular: p.popular, sort_order: p.sort_order },
        ]),
    ),
);
const savePack = (p: Pack) => router.patch(`/admin/ai-credits/packs/${p.id}`, drafts[p.id], { preserveScroll: true });
const deletePack = (p: Pack) => router.delete(`/admin/ai-credits/packs/${p.id}`, { preserveScroll: true });

const newPack = useForm({ name: '', price: 499, credits: 40, bonus_credits: 0, is_active: true, is_popular: false, sort_order: props.packs.length + 1 });
const addPack = () => newPack.post('/admin/ai-credits/packs', { preserveScroll: true, onSuccess: () => newPack.reset() });

const adjust = useForm({ employer: '', credits: 10, reason: '' });
const submitAdjust = () => adjust.post('/admin/ai-credits/adjust', { preserveScroll: true, onSuccess: () => adjust.reset() });

const q = ref(props.filters.q ?? '');
const type = ref(props.filters.type ?? '');
const search = () => router.get('/admin/ai-credits', { q: q.value || undefined, type: type.value || undefined }, { preserveScroll: true, preserveState: true, replace: true });
const go = (url: string | null) => {
    if (url) router.get(url, {}, { preserveScroll: true, preserveState: true });
};

const input = 'w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40';
const label = 'mb-1 block text-xs font-medium text-muted-foreground';
</script>

<template>
    <Head title="AI Credits" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader :icon="Coins" title="AI Call Credits" description="What a minute of AI calling costs, the packs employers recharge with, and every credit added or spent." />

        <!-- Stats -->
        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-2xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground">Recharge revenue (incl. GST)</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ inr(stats.revenue) }}</p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground">Recharges</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(stats.recharges) }}</p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground">Credits spent on calls</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(stats.credits_spent) }}</p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground">Unspent credits (all employers)</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ num(stats.outstanding) }}</p>
            </div>
        </section>

        <!-- Settings -->
        <section class="rounded-2xl border bg-card p-5 shadow-sm">
            <h2 class="border-b pb-3 font-semibold">Rate & limits</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label :class="label">Credits per minute of calling</label>
                    <input v-model.number="settingsForm.per_minute" type="number" min="1" :class="input" />
                    <InputError :message="settingsForm.errors.per_minute" />
                </div>
                <div>
                    <label :class="label">Minimum to switch AI calls on for a job</label>
                    <input v-model.number="settingsForm.min_balance" type="number" min="0" :class="input" />
                    <InputError :message="settingsForm.errors.min_balance" />
                </div>
                <div>
                    <label :class="label">Low-balance alert below</label>
                    <input v-model.number="settingsForm.low_alert" type="number" min="0" :class="input" />
                    <InputError :message="settingsForm.errors.low_alert" />
                </div>
                <div>
                    <label :class="label">Welcome credits for each employer (0 = off)</label>
                    <input v-model.number="settingsForm.welcome" type="number" min="0" :class="input" />
                    <InputError :message="settingsForm.errors.welcome" />
                </div>
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                Calls are charged per started minute, and only when the worker picks up. While a call is on the line it holds
                {{ settings.max_call_minutes }} minutes' worth ({{ settings.max_call_minutes * settingsForm.per_minute }} credits), its longest possible run, so an employer can never go below zero.
                Welcome credits reach each employer once, the first time they open their credits or the job form.
            </p>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="settingsForm.enabled" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" />
                    Charge credits for AI calls <span class="text-xs text-muted-foreground">(off = every AI call is free)</span>
                </label>
                <button
                    :disabled="settingsForm.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                    @click="saveSettings"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </section>

        <!-- Packs -->
        <section class="flex flex-col gap-3">
            <h2 class="font-semibold">Packs</h2>
            <div v-for="p in packs" :key="p.id" class="rounded-2xl border bg-card p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
                    <div>
                        <h3 class="font-semibold">{{ p.name }}</h3>
                        <p class="text-xs text-muted-foreground">
                            {{ inr(drafts[p.id].price) }} → {{ num(drafts[p.id].credits + drafts[p.id].bonus_credits) }} credits · {{ inr(p.price_with_gst) }} with GST · sold {{ p.sold }}
                        </p>
                    </div>
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                        :class="drafts[p.id].is_active ? 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-300' : 'bg-muted text-muted-foreground ring-border'"
                    >
                        {{ drafts[p.id].is_active ? 'On sale' : 'Hidden' }}
                    </span>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div><label :class="label">Name</label><input v-model="drafts[p.id].name" :class="input" /></div>
                    <div><label :class="label">Price ₹ (before GST)</label><input v-model.number="drafts[p.id].price" type="number" min="1" :class="input" /></div>
                    <div><label :class="label">Credits</label><input v-model.number="drafts[p.id].credits" type="number" min="1" :class="input" /></div>
                    <div><label :class="label">Bonus credits</label><input v-model.number="drafts[p.id].bonus_credits" type="number" min="0" :class="input" /></div>
                    <div><label :class="label">Order</label><input v-model.number="drafts[p.id].sort_order" type="number" min="0" :class="input" /></div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                    <div class="flex items-center gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="drafts[p.id].is_popular" type="checkbox" class="size-4 rounded border-input text-orange-600" /> Popular badge</label>
                        <label class="flex items-center gap-2"><input v-model="drafts[p.id].is_active" type="checkbox" class="size-4 rounded border-input text-orange-600" /> On sale</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="inline-flex items-center gap-1.5 rounded-xl border px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-500/10" @click="deletePack(p)">
                            <Trash2 class="size-4" /> Delete
                        </button>
                        <button class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95" @click="savePack(p)">
                            <Check class="size-4" /> Save
                        </button>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-dashed bg-card p-5">
                <h3 class="font-semibold">Add a pack</h3>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div><label :class="label">Name</label><input v-model="newPack.name" :class="input" placeholder="Business" /><InputError :message="newPack.errors.name" /></div>
                    <div><label :class="label">Price ₹ (before GST)</label><input v-model.number="newPack.price" type="number" min="1" :class="input" /><InputError :message="newPack.errors.price" /></div>
                    <div><label :class="label">Credits</label><input v-model.number="newPack.credits" type="number" min="1" :class="input" /><InputError :message="newPack.errors.credits" /></div>
                    <div><label :class="label">Bonus credits</label><input v-model.number="newPack.bonus_credits" type="number" min="0" :class="input" /></div>
                    <div><label :class="label">Order</label><input v-model.number="newPack.sort_order" type="number" min="0" :class="input" /></div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm"><input v-model="newPack.is_popular" type="checkbox" class="size-4 rounded border-input text-orange-600" /> Popular badge</label>
                    <button
                        :disabled="newPack.processing"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                        @click="addPack"
                    >
                        <Plus class="size-4" /> Add pack
                    </button>
                </div>
            </div>
        </section>

        <!-- Manual adjust -->
        <section class="rounded-2xl border bg-card p-5 shadow-sm">
            <h2 class="border-b pb-3 font-semibold">Add or take credits</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-[1.2fr_0.6fr_2fr_auto] sm:items-start">
                <div>
                    <label :class="label">Employer phone, email or ID</label>
                    <input v-model="adjust.employer" :class="input" placeholder="9000000001" />
                    <InputError :message="adjust.errors.employer" />
                </div>
                <div>
                    <label :class="label">Credits (− to take)</label>
                    <input v-model.number="adjust.credits" type="number" :class="input" />
                    <InputError :message="adjust.errors.credits" />
                </div>
                <div>
                    <label :class="label">Reason (the employer sees this)</label>
                    <input v-model="adjust.reason" :class="input" placeholder="Refund for a dropped call" />
                    <InputError :message="adjust.errors.reason" />
                </div>
                <button
                    :disabled="adjust.processing"
                    class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50 sm:mt-5"
                    @click="submitAdjust"
                >
                    <Check class="size-4" /> Apply
                </button>
            </div>
        </section>

        <!-- Ledger -->
        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 class="font-semibold">Ledger</h2>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="search">
                    <div class="relative">
                        <Search class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input v-model="q" placeholder="Employer, phone, email" class="w-56 rounded-xl border bg-background py-2 pr-3 pl-9 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                    </div>
                    <select v-model="type" class="rounded-xl border bg-background px-3 py-2 text-sm" @change="search">
                        <option value="">All types</option>
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </form>
            </div>

            <div class="overflow-x-auto rounded-2xl border bg-card shadow-sm">
                <table class="w-full min-w-[760px] text-sm">
                    <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">When</th>
                            <th class="px-4 py-2.5 font-medium">Employer</th>
                            <th class="px-4 py-2.5 font-medium">What</th>
                            <th class="px-4 py-2.5 text-right font-medium">Credits</th>
                            <th class="px-4 py-2.5 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in ledger.data" :key="row.id" class="border-b last:border-b-0">
                            <td class="px-4 py-3 text-xs whitespace-nowrap text-muted-foreground">{{ row.created_label }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ row.employer.name }}</div>
                                <div class="text-xs text-muted-foreground">{{ row.employer.phone }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs font-semibold text-muted-foreground">{{ row.type_label }}<template v-if="row.by"> · by {{ row.by }}</template></div>
                                <div>{{ row.description }}</div>
                                <div v-if="row.invoice || row.amount_paid" class="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                    <span v-if="row.amount_paid">Paid {{ inr(row.amount_paid) }}</span>
                                    <a v-if="row.invoice" :href="row.invoice.web_url" class="inline-flex items-center gap-1 text-orange-700 hover:underline dark:text-orange-300"><FileText class="size-3" /> {{ row.invoice.number }}</a>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums" :class="row.credits > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                                {{ row.credits > 0 ? '+' : '−' }}{{ num(Math.abs(row.credits)) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ num(row.balance_after) }}</td>
                        </tr>
                        <tr v-if="!ledger.data.length">
                            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">No credit movements yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="ledger.links.length > 3" class="flex flex-wrap items-center justify-center gap-1">
                <button
                    v-for="link in ledger.links"
                    :key="link.label"
                    :disabled="!link.url"
                    class="min-w-9 rounded-lg border px-3 py-1.5 text-sm transition disabled:opacity-40"
                    :class="link.active ? 'border-orange-500 bg-orange-500/10 font-semibold text-orange-600 dark:text-orange-300' : 'hover:bg-muted'"
                    @click="go(link.url)"
                    v-html="link.label"
                />
            </div>
        </section>
    </div>
</template>
