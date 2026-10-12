<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, BadgeCheck, Building2, CircleCheck, Clock, FileQuestion, Fingerprint, ShieldCheck, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DocKey = 'aadhaar' | 'pan' | 'gst';

interface DocCheck {
    status: 'passed' | 'invalid' | 'error';
    via: 'registry' | 'digilocker';
    verified: boolean;
    name_matches: boolean | null;
    message: string | null;
}

interface DocSummary {
    type: DocKey;
    label: string;
    missing: boolean;
    number: string | null;
    has_file: boolean;
    alternate: { type: string; label: string; number: string | null; has_file: boolean; reason: string } | null;
    check: DocCheck | null;
}

interface Kyc {
    status: 'pending' | 'verified' | 'rejected';
    remarks: string | null;
    business_type: string | null;
    auto_verified: boolean;
    documents: DocSummary[];
}

interface DocSpec {
    key: DocKey;
    label: string;
    number_field: string;
    hint: string;
    alternates: { key: string; label: string }[];
}

const props = defineProps<{
    role: 'worker' | 'employer';
    kyc: Kyc | null;
    business: { business_type: string | null; legal_name: string | null; registered_address: string | null } | null;
    verification: { required: boolean; can_post_jobs: boolean; message: string | null } | null;
    applyVerification: { required: boolean; can_apply: boolean; message: string | null } | null;
    digilocker: { available: boolean; verified: boolean; name: string | null; masked_aadhaar: string | null };
    autoVerify: boolean;
    reference: {
        business_types: { key: string; label: string; documents: DocKey[] }[];
        worker_documents: DocKey[];
        documents: DocSpec[];
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Verification', href: '/kyc' }] },
});

const isEmployer = props.role === 'employer';
const editing = ref(false);

const form = useForm<Record<string, string | boolean | File | null>>({
    business_type: props.business?.business_type ?? '',
    legal_name: props.business?.legal_name ?? '',
    registered_address: props.business?.registered_address ?? '',
    aadhaar_number: '',
    pan_number: '',
    gstin: '',
    ...Object.fromEntries(
        (['aadhaar', 'pan', 'gst'] as DocKey[]).flatMap((d) => {
            const previous = props.kyc?.documents.find((x) => x.type === d);

            return [
                [`${d}_doc`, null],
                [`${d}_missing`, previous?.missing ?? false],
                [`${d}_alt_type`, ''],
                [`${d}_alt_number`, ''],
                [`${d}_alt_doc`, null],
                [`${d}_reason`, previous?.alternate?.reason ?? ''],
            ];
        }),
    ),
});

const specs = computed(() => {
    const keys = isEmployer
        ? (props.reference.business_types.find((b) => b.key === form.business_type)?.documents ?? [])
        : props.reference.worker_documents;

    return keys.map((k) => props.reference.documents.find((d) => d.key === k)!);
});

const showForm = computed(() => !props.kyc || props.kyc.status === 'rejected' || editing.value);

const statusPill: Record<string, string> = {
    verified: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const onFile = (field: string, e: Event) => {
    form[field] = (e.target as HTMLInputElement).files?.[0] ?? null;
};

const fileName = (field: string) => (form[field] instanceof File ? (form[field] as File).name : null);

const hasStoredFile = (doc: DocKey, alt: boolean) => {
    const prev = props.kyc?.documents.find((d) => d.type === doc);

    return alt ? !!prev?.alternate?.has_file : !!prev?.has_file;
};

const err = (field: string) => (form.errors as Record<string, string | undefined>)[field];

// DigiLocker: the server answers with a redirect to DigiLocker's sign-in.
const openingDigilocker = ref(false);
const startDigilocker = () => {
    openingDigilocker.value = true;
    router.post('/kyc/digilocker', {}, { onFinish: () => (openingDigilocker.value = false) });
};

// A pending submission whose Aadhaar was typed in can still be sped up through DigiLocker.
const aadhaarAsked = computed(() => !!props.kyc?.documents.some((d) => d.type === 'aadhaar' && !d.missing));
const offerDigilockerWhilePending = computed(
    () => props.digilocker.available && !props.digilocker.verified && props.kyc?.status === 'pending' && aadhaarAsked.value,
);

const checkLine = (d: DocSummary): { tone: 'ok' | 'warn'; text: string } | null => {
    if (!d.check) {
        return null;
    }

    if (d.check.verified) {
        return { tone: 'ok', text: d.check.via === 'digilocker' ? 'Verified through DigiLocker' : 'Checked with government records' };
    }

    if (d.check.status === 'passed' && d.check.name_matches === false) {
        return { tone: 'warn', text: 'Name does not match your other documents — our team will check' };
    }

    return { tone: 'warn', text: d.check.message ?? 'Could not be checked automatically — our team will check' };
};

const submit = () =>
    form.post('/kyc', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (editing.value = false),
    });
</script>

<template>
    <Head title="Verification" />

    <div class="flex w-full flex-col gap-6 p-4 md:p-6 lg:p-8">
        <PageHeader
            :icon="ShieldCheck"
            :title="isEmployer ? 'Business verification' : 'KYC Verification'"
            :description="isEmployer ? 'Verify your business to post jobs' : 'Verify your Aadhaar and PAN to apply to jobs'"
        />

        <div
            v-if="verification && !verification.can_post_jobs && verification.message"
            class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300"
        >
            {{ verification.message }}
        </div>

        <div
            v-if="applyVerification && !applyVerification.can_apply && applyVerification.message"
            class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300"
        >
            {{ applyVerification.message }}
        </div>

        <!-- Status card -->
        <div v-if="kyc" class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-muted-foreground">Current status</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[kyc.status]">
                    {{ kyc.status }}
                </span>
            </div>
            <ul class="mt-3 space-y-1.5 text-sm text-muted-foreground">
                <li v-for="d in kyc.documents" :key="d.type">
                    <span class="font-medium text-foreground">{{ d.label }}:</span>{{ ' ' }}
                    <template v-if="d.missing && d.alternate">
                        not available — sent {{ d.alternate.label }}<span v-if="d.alternate.number" class="font-mono"> ({{ d.alternate.number }})</span>
                    </template>
                    <span v-else class="font-mono">{{ d.number ?? '—' }}</span>
                    <span
                        v-if="checkLine(d)"
                        class="mt-0.5 flex items-center gap-1 text-xs"
                        :class="checkLine(d)!.tone === 'ok' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'"
                    >
                        <CircleCheck v-if="checkLine(d)!.tone === 'ok'" class="size-3.5" />
                        <AlertTriangle v-else class="size-3.5" />
                        {{ checkLine(d)!.text }}
                    </span>
                </li>
                <li v-if="kyc.status === 'rejected' && kyc.remarks" class="text-rose-600 dark:text-rose-400">Reason: {{ kyc.remarks }}</li>
            </ul>
            <button
                v-if="kyc.status === 'pending' && !editing"
                type="button"
                class="mt-3 text-sm font-semibold text-primary underline-offset-2 hover:underline"
                @click="editing = true"
            >
                Edit and resubmit
            </button>
        </div>

        <div v-if="kyc && kyc.status === 'verified' && !editing" class="flex items-center gap-3 rounded-2xl border border-orange-500/30 bg-orange-500/5 p-5">
            <span class="flex size-10 items-center justify-center rounded-full bg-orange-500/15 text-orange-600 dark:text-orange-300"><BadgeCheck class="size-6" /></span>
            <div>
                <p class="font-semibold">You're verified</p>
                <p class="text-sm text-muted-foreground">
                    {{ kyc.auto_verified ? 'Your documents matched government records.' : 'Your documents have been approved.' }}
                    <template v-if="applyVerification?.required">You can apply to jobs.</template>
                </p>
            </div>
        </div>

        <div v-else-if="kyc && kyc.status === 'pending' && !editing" class="flex items-center gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5">
            <span class="flex size-10 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-300"><Clock class="size-6" /></span>
            <div>
                <p class="font-semibold">Under review</p>
                <p class="text-sm text-muted-foreground">Our team checks submissions by hand, usually within a working day.</p>
            </div>
        </div>

        <div v-if="offerDigilockerWhilePending && !editing" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-5 shadow-sm">
            <div>
                <p class="font-semibold">Skip the wait</p>
                <p class="text-sm text-muted-foreground">Verify your Aadhaar through DigiLocker and get verified in a minute.</p>
            </div>
            <button
                type="button"
                :disabled="openingDigilocker"
                class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
                @click="startDigilocker"
            >
                <Fingerprint class="size-4" /> Verify with DigiLocker
            </button>
        </div>

        <form v-if="showForm" class="grid items-start gap-5 lg:grid-cols-2" @submit.prevent="submit">
            <!-- Business details (employers) -->
            <section v-if="isEmployer" class="rounded-2xl border bg-card p-5 shadow-sm md:p-6 lg:col-span-2">
                <h2 class="mb-4 flex items-center gap-2 text-sm font-semibold text-muted-foreground">
                    <Building2 class="size-4 text-orange-500" /> Business details
                </h2>
                <div class="grid gap-4">
                    <div class="grid gap-2">
                        <Label for="business_type">Business type</Label>
                        <select id="business_type" v-model="form.business_type" class="h-10 rounded-md border bg-background px-3 text-sm">
                            <option value="" disabled>Select…</option>
                            <option v-for="b in reference.business_types" :key="b.key" :value="b.key">{{ b.label }}</option>
                        </select>
                        <InputError :message="err('business_type')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="legal_name">{{ form.business_type === 'individual' ? 'Full name (as on PAN)' : 'Legal business name' }}</Label>
                        <Input id="legal_name" v-model="form.legal_name as string" />
                        <InputError :message="err('legal_name')" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="registered_address">{{ form.business_type === 'individual' ? 'Address' : 'Registered address' }}</Label>
                        <textarea id="registered_address" v-model="form.registered_address as string" rows="2" class="rounded-md border bg-background px-3 py-2 text-sm" />
                        <InputError :message="err('registered_address')" />
                    </div>
                </div>
            </section>

            <!-- One card per document -->
            <section v-for="spec in specs" :key="spec.key" class="rounded-2xl border bg-card p-5 shadow-sm md:p-6">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold">{{ spec.label }}</h2>
                    <label class="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground">
                        <input v-model="form[`${spec.key}_missing`]" type="checkbox" class="size-4 accent-orange-600" />
                        I don't have this
                    </label>
                </div>

                <!-- Aadhaar already verified through DigiLocker: nothing to type. -->
                <div
                    v-if="spec.key === 'aadhaar' && !form.aadhaar_missing && digilocker.verified"
                    class="flex items-center gap-3 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300"
                >
                    <CircleCheck class="size-5 shrink-0" />
                    <div>
                        <p class="font-semibold">Verified through DigiLocker</p>
                        <p class="text-xs opacity-80">{{ digilocker.name }} · <span class="font-mono">{{ digilocker.masked_aadhaar }}</span></p>
                    </div>
                </div>

                <template v-else-if="!form[`${spec.key}_missing`]">
                <div v-if="spec.key === 'aadhaar' && digilocker.available" class="mb-4 grid gap-2">
                    <button
                        type="button"
                        :disabled="openingDigilocker"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-60"
                        @click="startDigilocker"
                    >
                        <Fingerprint class="size-4" /> Verify with DigiLocker
                    </button>
                    <p class="text-center text-xs text-muted-foreground">Instant. Or type your Aadhaar number below and our team will check it.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label :for="spec.number_field">Number</Label>
                        <Input :id="spec.number_field" v-model="form[spec.number_field] as string" :placeholder="spec.hint" class="uppercase" />
                        <InputError :message="err(spec.number_field)" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Photo / PDF <span class="font-normal text-muted-foreground">(optional)</span></Label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed bg-muted/30 px-4 py-2.5 text-xs transition hover:border-orange-500/50">
                            <Upload class="size-4 text-orange-500" />
                            <span class="truncate">{{ fileName(`${spec.key}_doc`) ?? (hasStoredFile(spec.key, false) ? 'On file — upload to replace' : 'Upload image / PDF') }}</span>
                            <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" @change="onFile(`${spec.key}_doc`, $event)" />
                        </label>
                        <InputError :message="err(`${spec.key}_doc`)" />
                    </div>
                </div>
                </template>

                <div v-else class="grid gap-4">
                    <p class="flex items-start gap-2 rounded-xl bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                        <FileQuestion class="mt-0.5 size-4 shrink-0" />
                        Send another document instead. Our team will check it by hand.
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>Document you have</Label>
                            <select v-model="form[`${spec.key}_alt_type`]" class="h-10 rounded-md border bg-background px-3 text-sm">
                                <option value="" disabled>Select…</option>
                                <option v-for="a in spec.alternates" :key="a.key" :value="a.key">{{ a.label }}</option>
                            </select>
                            <InputError :message="err(`${spec.key}_alt_type`)" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Its number (optional)</Label>
                            <Input v-model="form[`${spec.key}_alt_number`] as string" />
                            <InputError :message="err(`${spec.key}_alt_number`)" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label>Photo / PDF</Label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed bg-muted/30 px-4 py-2.5 text-xs transition hover:border-orange-500/50">
                            <Upload class="size-4 text-orange-500" />
                            <span class="truncate">{{ fileName(`${spec.key}_alt_doc`) ?? (hasStoredFile(spec.key, true) ? 'On file — upload to replace' : 'Upload image / PDF') }}</span>
                            <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" @change="onFile(`${spec.key}_alt_doc`, $event)" />
                        </label>
                        <InputError :message="err(`${spec.key}_alt_doc`)" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Why don't you have it?</Label>
                        <textarea v-model="form[`${spec.key}_reason`] as string" rows="2" class="rounded-md border bg-background px-3 py-2 text-sm" />
                        <InputError :message="err(`${spec.key}_reason`)" />
                    </div>
                </div>
            </section>

            <div class="lg:col-span-2">
            <button
                type="submit"
                :disabled="form.processing || (isEmployer && !form.business_type)"
                class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
            >
                Submit for verification
            </button>
            <p v-if="autoVerify" class="mt-2 text-xs text-muted-foreground">
                {{ isEmployer ? 'PAN and GSTIN are' : 'Your PAN is' }} checked against government records the moment you submit.
            </p>
            </div>
        </form>
    </div>
</template>
