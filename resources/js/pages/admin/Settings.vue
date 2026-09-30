<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Layers, Receipt, Settings as SettingsIcon } from '@lucide/vue';
import { computed, reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';

type SettingKey =
    | 'first_post_free_enabled'
    | 'kyc_verification_enabled'
    | 'ai_auto_shortlist_enabled'
    | 'ai_auto_reject_enabled'
    | 'ai_screening_call_enabled';

const props = defineProps<{
    settings: Record<SettingKey, boolean> & {
        ai_auto_shortlist_threshold: number;
        ai_auto_reject_below: number;
        applicant_first_batch: number;
        applicant_next_batch: number;
    };
    billing: {
        gst_enabled: boolean;
        gst_percent: number;
        seller_name: string;
        seller_address: string;
        seller_gstin: string;
        seller_state: string | null;
        sac_code: string;
        invoice_prefix: string;
        plans: { name: string; price: number; interval: string }[];
    };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Settings', href: '/admin/settings' }] } });

const form = reactive({
    first_post_free_enabled: props.settings.first_post_free_enabled,
    kyc_verification_enabled: props.settings.kyc_verification_enabled,
    ai_auto_shortlist_enabled: props.settings.ai_auto_shortlist_enabled,
    ai_auto_shortlist_threshold: props.settings.ai_auto_shortlist_threshold,
    ai_auto_reject_enabled: props.settings.ai_auto_reject_enabled,
    ai_auto_reject_below: props.settings.ai_auto_reject_below,
    ai_screening_call_enabled: props.settings.ai_screening_call_enabled,
    applicant_first_batch: props.settings.applicant_first_batch,
    applicant_next_batch: props.settings.applicant_next_batch,
});

const inputClass =
    'mt-1.5 w-full rounded-xl border bg-background px-3 py-2 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20';

const toggles: { key: SettingKey; title: string; description: string }[] = [
    {
        key: 'first_post_free_enabled',
        title: 'First job post free',
        description:
            'When on, every employer can post their first job for free — even without a ' +
            'subscription. After that, an active plan is required to post more jobs. ' +
            'When off, posting jobs always requires an active plan.',
    },
    {
        key: 'kyc_verification_enabled',
        title: 'KYC verification',
        description:
            'When on, karigars and employers can submit PAN/Aadhaar/GST for verification and ' +
            'approved ones show a verified badge. When off, the feature disappears everywhere — ' +
            'the apps hide their KYC screens, the badge stops showing, and the KYC endpoints ' +
            'return 404. Submitted documents are kept, so turning it back on restores them.',
    },
    {
        key: 'ai_auto_shortlist_enabled',
        title: 'AI auto-shortlist',
        description:
            'Every applicant is always scored by the AI and ranked best-match-first — that never ' +
            'changes. This only controls whether a high scorer is shortlisted automatically. ' +
            'When off, shortlisting stays a manual employer action. When on, any applicant ' +
            'scoring at or above the threshold below is shortlisted and the karigar is notified.',
    },
    {
        key: 'ai_auto_reject_enabled',
        title: 'AI auto-reject',
        description:
            'When on, an applicant scoring below the floor below is rejected automatically and told so. ' +
            'Only untouched applications are affected — never one the employer has already shortlisted, ' +
            'interviewed or decided. Leave this off unless you trust the scores: a wrong auto-reject ' +
            'costs a real karigar a real job.',
    },
    {
        key: 'ai_screening_call_enabled',
        title: 'AI screening calls',
        description:
            'When on, an auto-shortlisted applicant is rung on their phone by an AI agent that checks ' +
            'they are still interested and asks a few first-round screening questions. The employer gets ' +
            'the answers as a summary and decides; nothing is booked on the call. Needs auto-shortlist on and a ' +
            'configured voice provider; without both, nothing is dialled. Karigars who opted out are ' +
            'never called.',
    },
];

// Mirrors AiMatcher's recommendation buckets, so the admin can see how wide a
// net the chosen threshold casts before saving it.
const thresholdHint = computed(() => {
    const n = form.ai_auto_shortlist_threshold;
    const bucket = n >= 80 ? 'only "strong match" applicants' : n >= 60 ? '"good match" and above' : '"maybe" and above — a wide net';

    return `Shortlists ${bucket}. Each auto-shortlist notifies the karigar, so a lower number means more notifications.`;
});

// Warn when the two bands are set close together — the gap between them is the
// range the employer still decides for themselves.
const rejectHint = computed(() => {
    const floor = form.ai_auto_reject_below;
    const top = form.ai_auto_shortlist_enabled ? form.ai_auto_shortlist_threshold : 100;
    const band = `Applicants scoring under ${floor}% are rejected and notified.`;

    return floor >= top
        ? `${band} This overlaps your shortlist threshold — widen the gap.`
        : `${band} ${floor}–${top}% is left for the employer to decide.`;
});

const save = () => {
    router.patch('/admin/settings', form, { preserveScroll: true });
};

// ── Billing & GST ───────────────────────────────────────────────────
const billing = useForm({
    gst_enabled: props.billing.gst_enabled,
    gst_percent: props.billing.gst_percent,
    seller_name: props.billing.seller_name,
    seller_address: props.billing.seller_address,
    seller_gstin: props.billing.seller_gstin,
    sac_code: props.billing.sac_code,
});

const saveBilling = () => {
    billing.patch('/admin/settings/billing', { preserveScroll: true });
};

const rate = computed(() => (billing.gst_enabled ? Number(billing.gst_percent) || 0 : 0));
const round2 = (n: number) => Math.round(n * 100) / 100;
const inr = (n: number) => '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// What each plan will actually charge, worked out the way Gst::quote() does.
const planPreview = computed(() =>
    props.billing.plans.map((plan) => {
        const gst = round2((plan.price * rate.value) / 100);
        const cgst = round2(gst / 2);

        return { ...plan, gst, cgst, sgst: round2(gst - cgst), total: round2(plan.price + gst) };
    }),
);

// The seller's state, read live off the GSTIN being typed.
const STATE_CODES: Record<string, string> = {
    '01': 'Jammu and Kashmir', '02': 'Himachal Pradesh', '03': 'Punjab', '04': 'Chandigarh', '05': 'Uttarakhand',
    '06': 'Haryana', '07': 'Delhi', '08': 'Rajasthan', '09': 'Uttar Pradesh', '10': 'Bihar', '11': 'Sikkim',
    '12': 'Arunachal Pradesh', '13': 'Nagaland', '14': 'Manipur', '15': 'Mizoram', '16': 'Tripura', '17': 'Meghalaya',
    '18': 'Assam', '19': 'West Bengal', '20': 'Jharkhand', '21': 'Odisha', '22': 'Chhattisgarh', '23': 'Madhya Pradesh',
    '24': 'Gujarat', '26': 'Dadra and Nagar Haveli and Daman and Diu', '27': 'Maharashtra', '29': 'Karnataka', '30': 'Goa',
    '31': 'Lakshadweep', '32': 'Kerala', '33': 'Tamil Nadu', '34': 'Puducherry', '35': 'Andaman and Nicobar Islands',
    '36': 'Telangana', '37': 'Andhra Pradesh', '38': 'Ladakh',
};
const sellerState = computed(() => STATE_CODES[billing.seller_gstin.trim().slice(0, 2)] ?? null);
</script>

<template>
    <Head title="Settings" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :icon="SettingsIcon"
            title="Settings"
            description="App-wide feature toggles, and GST on what we sell."
        />

        <div class="rounded-2xl border bg-card p-5 shadow-sm">
            <div
                v-for="(toggle, index) in toggles"
                :key="toggle.key"
                :class="index > 0 ? 'mt-5 border-t pt-5' : ''"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="font-semibold">{{ toggle.title }}</h3>
                        <p class="mt-1 text-sm text-muted-foreground">{{ toggle.description }}</p>
                    </div>

                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form[toggle.key]"
                        :aria-label="toggle.title"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                        :class="form[toggle.key] ? 'bg-emerald-500' : 'bg-muted'"
                        @click="form[toggle.key] = !form[toggle.key]"
                    >
                        <span
                            class="inline-block size-5 transform rounded-full bg-white shadow transition"
                            :class="form[toggle.key] ? 'translate-x-5' : 'translate-x-0.5'"
                        />
                    </button>
                </div>

                <!-- Threshold only matters while auto-shortlisting is on. -->
                <div
                    v-if="toggle.key === 'ai_auto_shortlist_enabled' && form.ai_auto_shortlist_enabled"
                    class="mt-4 rounded-xl bg-muted/50 p-4"
                >
                    <label class="font-medium text-sm" for="ai-threshold">
                        Auto-shortlist at score
                    </label>
                    <div class="mt-2 flex items-center gap-3">
                        <input
                            id="ai-threshold"
                            v-model.number="form.ai_auto_shortlist_threshold"
                            type="range"
                            min="40"
                            max="100"
                            step="5"
                            class="h-2 w-full max-w-xs accent-orange-500"
                        />
                        <span class="w-14 shrink-0 text-right font-semibold tabular-nums">
                            {{ form.ai_auto_shortlist_threshold }}%
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">
                        {{ thresholdHint }}
                    </p>
                </div>

                <!-- Reject floor only matters while auto-reject is on. -->
                <div
                    v-if="toggle.key === 'ai_auto_reject_enabled' && form.ai_auto_reject_enabled"
                    class="mt-4 rounded-xl border border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900 dark:bg-rose-950/30"
                >
                    <label class="font-medium text-sm" for="ai-reject-below">
                        Auto-reject below score
                    </label>
                    <div class="mt-2 flex items-center gap-3">
                        <input
                            id="ai-reject-below"
                            v-model.number="form.ai_auto_reject_below"
                            type="range"
                            min="5"
                            max="40"
                            step="5"
                            class="h-2 w-full max-w-xs accent-rose-500"
                        />
                        <span class="w-14 shrink-0 text-right font-semibold tabular-nums">
                            {{ form.ai_auto_reject_below }}%
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">{{ rejectHint }}</p>
                </div>
            </div>

            <!-- Applicants arrive in batches (ApplicantAccess). -->
            <div class="mt-5 border-t pt-5">
                <h3 class="flex items-center gap-2 font-semibold"><Layers class="size-4 text-primary" /> Applicants in batches</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    An employer sees a job's applicants a batch at a time, oldest first. The next batch opens once every
                    applicant shown so far is shortlisted, hired or rejected. Shortlisted and hired applicants always stay
                    visible, even after the employer's plan ends.
                </p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium">
                        First batch
                        <input v-model.number="form.applicant_first_batch" type="number" min="1" max="500" :class="inputClass" />
                    </label>
                    <label class="text-sm font-medium">
                        Each batch after that
                        <input v-model.number="form.applicant_next_batch" type="number" min="1" max="500" :class="inputClass" />
                    </label>
                </div>
            </div>

            <div class="mt-5 flex justify-end border-t pt-4">
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95"
                    @click="save"
                >
                    <Check class="size-4" /> Save
                </button>
            </div>
        </div>

        <div class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="flex items-center gap-2 font-semibold"><Receipt class="size-4 text-primary" /> Billing &amp; GST</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        GST is added on top of every plan price and collected in the same Razorpay payment. A buyer in
                        the seller's state pays CGST + SGST (half each); any other state pays IGST. The buyer's state is
                        read from their GSTIN, else from their profile. Changing the rate only affects new purchases —
                        issued invoices keep theirs.
                    </p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="billing.gst_enabled"
                    aria-label="Charge GST"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition"
                    :class="billing.gst_enabled ? 'bg-emerald-500' : 'bg-muted'"
                    @click="billing.gst_enabled = !billing.gst_enabled"
                >
                    <span
                        class="inline-block size-5 transform rounded-full bg-white shadow transition"
                        :class="billing.gst_enabled ? 'translate-x-5' : 'translate-x-0.5'"
                    />
                </button>
            </div>

            <div class="mt-5 grid gap-4 border-t pt-5 sm:grid-cols-2">
                <label class="text-sm font-medium">
                    GST rate (%)
                    <input v-model.number="billing.gst_percent" type="number" min="0" max="28" step="0.01" :class="inputClass" :disabled="!billing.gst_enabled" />
                    <span v-if="billing.errors.gst_percent" class="mt-1 block text-xs text-rose-600">{{ billing.errors.gst_percent }}</span>
                </label>
                <label class="text-sm font-medium">
                    SAC code
                    <input v-model="billing.sac_code" type="text" inputmode="numeric" maxlength="8" :class="inputClass" />
                    <span v-if="billing.errors.sac_code" class="mt-1 block text-xs text-rose-600">{{ billing.errors.sac_code }}</span>
                    <span v-else class="mt-1 block text-xs font-normal text-muted-foreground">Printed on every invoice. Confirm the right code with your CA.</span>
                </label>
                <label class="text-sm font-medium sm:col-span-2">
                    Seller legal name
                    <input v-model="billing.seller_name" type="text" :class="inputClass" />
                    <span v-if="billing.errors.seller_name" class="mt-1 block text-xs text-rose-600">{{ billing.errors.seller_name }}</span>
                </label>
                <label class="text-sm font-medium sm:col-span-2">
                    Seller address (with city and PIN)
                    <textarea v-model="billing.seller_address" rows="2" :class="inputClass" />
                    <span v-if="billing.errors.seller_address" class="mt-1 block text-xs text-rose-600">{{ billing.errors.seller_address }}</span>
                </label>
                <label class="text-sm font-medium sm:col-span-2">
                    Seller GSTIN
                    <input v-model="billing.seller_gstin" type="text" maxlength="15" :class="[inputClass, 'uppercase tracking-wider']" />
                    <span v-if="billing.errors.seller_gstin" class="mt-1 block text-xs text-rose-600">{{ billing.errors.seller_gstin }}</span>
                    <span v-else class="mt-1 block text-xs font-normal text-muted-foreground">
                        Registered in <strong class="text-foreground">{{ sellerState ?? 'unknown state' }}</strong> —
                        buyers there pay CGST + SGST, everyone else IGST.
                    </span>
                </label>
            </div>

            <div class="mt-5 overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Plan</th>
                            <th class="px-3 py-2 text-right font-medium">Price</th>
                            <th class="px-3 py-2 text-right font-medium">GST {{ rate }}%</th>
                            <th class="px-3 py-2 text-right font-medium">Customer pays</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="plan in planPreview" :key="plan.name">
                            <td class="px-3 py-2 font-medium">{{ plan.name }} <span class="text-xs font-normal capitalize text-muted-foreground">/ {{ plan.interval }}</span></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ inr(plan.price) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ inr(plan.gst) }}
                                <div v-if="plan.gst" class="text-[11px] text-muted-foreground">
                                    {{ sellerState ?? 'Same state' }}: CGST {{ inr(plan.cgst) }} + SGST {{ inr(plan.sgst) }} · other states: IGST {{ inr(plan.gst) }}
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ inr(plan.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-muted-foreground">
                Invoices are numbered {{ props.billing.invoice_prefix }}-YYYY-##### and emailed to the employer on payment. Plan prices and limits are edited under Plans.
            </p>

            <div class="mt-5 flex justify-end border-t pt-4">
                <button
                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                    :disabled="billing.processing"
                    @click="saveBilling"
                >
                    <Check class="size-4" /> Save billing
                </button>
            </div>
        </div>
    </div>
</template>
