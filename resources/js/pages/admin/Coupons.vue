<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Pencil, Plus, TicketPercent, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

interface Coupon {
    id: number;
    code: string;
    description: string | null;
    discount_type: 'percent' | 'flat';
    discount_value: string;
    max_discount_amount: string | null;
    min_amount: string | null;
    razorpay_offer_id: string | null;
    plan_ids: number[] | null;
    max_redemptions: number | null;
    redeemed_count: number;
    per_user_limit: number;
    starts_at: string | null;
    expires_at: string | null;
    is_active: boolean;
    redemptions_count: number;
}

interface PlanOption {
    id: number;
    name: string;
    price: string;
}

defineProps<{ coupons: Coupon[]; plans: PlanOption[] }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Coupons', href: '/admin/coupons' }] } });

const editingId = ref<number | null>(null);

const blank = () => ({
    code: '',
    description: '',
    discount_type: 'percent' as 'percent' | 'flat',
    discount_value: 10,
    max_discount_amount: null as number | null,
    min_amount: null as number | null,
    razorpay_offer_id: '',
    plan_ids: [] as number[],
    max_redemptions: null as number | null,
    per_user_limit: 1,
    starts_at: '',
    expires_at: '',
    is_active: true,
});

const form = useForm(blank());

const dt = (v: string | null) => (v ? v.slice(0, 16) : '');

const startEdit = (c: Coupon) => {
    editingId.value = c.id;
    form.defaults(blank());
    form.reset();
    form.code = c.code;
    form.description = c.description ?? '';
    form.discount_type = c.discount_type;
    form.discount_value = Number(c.discount_value);
    form.max_discount_amount = c.max_discount_amount != null ? Number(c.max_discount_amount) : null;
    form.min_amount = c.min_amount != null ? Number(c.min_amount) : null;
    form.razorpay_offer_id = c.razorpay_offer_id ?? '';
    form.plan_ids = c.plan_ids ?? [];
    form.max_redemptions = c.max_redemptions;
    form.per_user_limit = c.per_user_limit;
    form.starts_at = dt(c.starts_at);
    form.expires_at = dt(c.expires_at);
    form.is_active = c.is_active;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelEdit = () => {
    editingId.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => cancelEdit() };
    if (editingId.value) {
        form.transform((d) => d).patch(`/admin/coupons/${editingId.value}`, opts);
    } else {
        form.post('/admin/coupons', opts);
    }
};

const remove = (c: Coupon) => {
    if (window.confirm(`Delete coupon "${c.code}"?`)) {
        router.delete(`/admin/coupons/${c.id}`, { preserveScroll: true });
    }
};

const togglePlan = (id: number) => {
    form.plan_ids = form.plan_ids.includes(id) ? form.plan_ids.filter((p) => p !== id) : [...form.plan_ids, id];
};

const discountLabel = (c: Coupon) =>
    c.discount_type === 'percent' ? `${Number(c.discount_value)}% off` : `₹${Number(c.discount_value)} off`;
</script>

<template>
    <Head title="Coupons" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="TicketPercent" title="Discount Coupons" description="Create discount codes employers can apply at subscription checkout." />

        <!-- Create / edit form -->
        <form class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">{{ editingId ? 'Edit coupon' : 'New coupon' }}</h3>
                <button v-if="editingId" type="button" class="text-xs text-muted-foreground hover:text-foreground" @click="cancelEdit">Cancel edit</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Code</label>
                    <input v-model="form.code" placeholder="WELCOME20" class="w-full rounded-xl border bg-background px-3 py-2 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                    <p v-if="form.errors.code" class="mt-1 text-xs text-rose-500">{{ form.errors.code }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Description (optional)</label>
                    <input v-model="form.description" placeholder="First month launch offer" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Discount type</label>
                    <select v-model="form.discount_type" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40">
                        <option value="percent">Percentage (%)</option>
                        <option value="flat">Flat amount (₹)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">{{ form.discount_type === 'percent' ? 'Percent off' : 'Amount off (₹)' }}</label>
                    <input v-model.number="form.discount_value" type="number" min="0" step="0.01" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                    <p v-if="form.errors.discount_value" class="mt-1 text-xs text-rose-500">{{ form.errors.discount_value }}</p>
                </div>
                <div v-if="form.discount_type === 'percent'">
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Max discount cap ₹ (optional)</label>
                    <input v-model.number="form.max_discount_amount" type="number" min="0" step="0.01" placeholder="e.g. 500" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Minimum plan price ₹ (optional)</label>
                    <input v-model.number="form.min_amount" type="number" min="0" step="0.01" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Total redemption limit (blank = unlimited)</label>
                    <input v-model.number="form.max_redemptions" type="number" min="1" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Per-user limit (0 = unlimited)</label>
                    <input v-model.number="form.per_user_limit" type="number" min="0" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Starts at (optional)</label>
                    <input v-model="form.starts_at" type="datetime-local" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-muted-foreground">Expires at (optional)</label>
                    <input v-model="form.expires_at" type="datetime-local" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                    <p v-if="form.errors.expires_at" class="mt-1 text-xs text-rose-500">{{ form.errors.expires_at }}</p>
                </div>
            </div>

            <!-- Razorpay offer -->
            <div>
                <label class="mb-1 block text-xs font-medium text-muted-foreground">Razorpay Offer ID (optional but recommended)</label>
                <input v-model="form.razorpay_offer_id" placeholder="offer_XXXXXXXXXXXX" class="w-full rounded-xl border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/40" />
                <p class="mt-1 text-xs text-muted-foreground">
                    Create a matching offer in the Razorpay Dashboard and paste its ID here so Razorpay actually charges the discounted amount. Leave blank to show the discount to users without adjusting the Razorpay charge.
                </p>
            </div>

            <!-- Plans -->
            <div>
                <label class="mb-1.5 block text-xs font-medium text-muted-foreground">Applies to plans (none selected = all plans)</label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="p in plans"
                        :key="p.id"
                        type="button"
                        class="rounded-full px-3 py-1.5 text-xs font-medium ring-1 ring-inset transition"
                        :class="form.plan_ids.includes(p.id) ? 'bg-orange-500/10 text-orange-600 ring-orange-500/30 dark:text-orange-300' : 'bg-muted text-muted-foreground ring-border hover:bg-muted/70'"
                        @click="togglePlan(p.id)"
                    >
                        {{ p.name }} · ₹{{ p.price }}
                    </button>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-input text-orange-600 focus:ring-orange-500/40" />
                Active
            </label>

            <div>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                >
                    <component :is="editingId ? Check : Plus" class="size-4" /> {{ editingId ? 'Save changes' : 'Create coupon' }}
                </button>
            </div>
        </form>

        <!-- List -->
        <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div v-if="!coupons.length" class="px-5 py-10 text-center text-sm text-muted-foreground">No coupons yet.</div>
            <div v-for="c in coupons" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4 last:border-0">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-lg bg-muted px-2 py-0.5 font-mono text-sm font-semibold">{{ c.code }}</span>
                        <span class="inline-flex items-center rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-semibold text-orange-600 ring-1 ring-inset ring-orange-500/20 dark:text-orange-300">{{ discountLabel(c) }}</span>
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                            :class="c.is_active ? 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20 dark:text-emerald-300' : 'bg-muted text-muted-foreground ring-border'"
                        >
                            {{ c.is_active ? 'Active' : 'Paused' }}
                        </span>
                        <span v-if="!c.razorpay_offer_id" class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-600 ring-1 ring-inset ring-amber-500/20 dark:text-amber-300">Display-only</span>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <span v-if="c.description">{{ c.description }} · </span>
                        Used {{ c.redemptions_count }}<template v-if="c.max_redemptions"> / {{ c.max_redemptions }}</template>
                        · {{ c.per_user_limit === 0 ? 'unlimited per user' : c.per_user_limit + ' per user' }}
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <button class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground" @click="startEdit(c)">
                        <Pencil class="size-3.5" /> Edit
                    </button>
                    <button class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-500 transition hover:bg-rose-500/10" @click="remove(c)">
                        <Trash2 class="size-3.5" /> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
