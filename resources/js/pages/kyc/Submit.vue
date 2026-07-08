<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Clock, ShieldCheck, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Kyc {
    status: 'pending' | 'verified' | 'rejected';
    masked_pan: string | null;
    masked_aadhaar: string | null;
    remarks: string | null;
}

const props = defineProps<{ kyc: Kyc | null }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'KYC Verification', href: '/kyc' }] },
});

const form = useForm<{
    pan_number: string;
    aadhaar_number: string;
    pan_doc: File | null;
    aadhaar_doc: File | null;
}>({
    pan_number: '',
    aadhaar_number: '',
    pan_doc: null,
    aadhaar_doc: null,
});

const statusPill: Record<string, string> = {
    verified: 'bg-orange-500/10 text-orange-600 ring-orange-500/20 dark:text-orange-300',
    rejected: 'bg-rose-500/10 text-rose-600 ring-rose-500/20 dark:text-rose-300',
    pending: 'bg-amber-500/10 text-amber-600 ring-amber-500/20 dark:text-amber-300',
};

const onFile = (field: 'pan_doc' | 'aadhaar_doc', e: Event) => {
    const target = e.target as HTMLInputElement;
    form[field] = target.files?.[0] ?? null;
};

const submit = () => form.post('/kyc', { preserveScroll: true, forceFormData: true });
</script>

<template>
    <Head title="KYC Verification" />

    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :icon="ShieldCheck" title="KYC Verification" description="Verify your PAN and Aadhaar to build trust" />

        <!-- Status card -->
        <div v-if="kyc" class="rounded-2xl border bg-card p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-muted-foreground">Current status</span>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="statusPill[kyc.status]">
                    {{ kyc.status }}
                </span>
            </div>
            <div class="mt-3 space-y-1 text-sm text-muted-foreground">
                <p v-if="kyc.masked_pan">PAN: <span class="font-mono">{{ kyc.masked_pan }}</span></p>
                <p v-if="kyc.masked_aadhaar">Aadhaar: <span class="font-mono">{{ kyc.masked_aadhaar }}</span></p>
                <p v-if="kyc.status === 'rejected' && kyc.remarks" class="text-rose-600 dark:text-rose-400">Reason: {{ kyc.remarks }}</p>
            </div>
        </div>

        <!-- Verified / pending banners -->
        <div v-if="kyc && kyc.status === 'verified'" class="flex items-center gap-3 rounded-2xl border border-orange-500/30 bg-orange-500/5 p-5">
            <span class="flex size-10 items-center justify-center rounded-full bg-orange-500/15 text-orange-600 dark:text-orange-300"><BadgeCheck class="size-6" /></span>
            <div>
                <p class="font-semibold">You're verified</p>
                <p class="text-sm text-muted-foreground">Your KYC has been approved. ✅</p>
            </div>
        </div>

        <div v-else-if="kyc && kyc.status === 'pending'" class="flex items-center gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5">
            <span class="flex size-10 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-300"><Clock class="size-6" /></span>
            <div>
                <p class="font-semibold">Under review</p>
                <p class="text-sm text-muted-foreground">Your KYC is under review. Please wait for admin approval.</p>
            </div>
        </div>

        <!-- Submission form -->
        <form v-if="!kyc || kyc.status === 'rejected'" class="rounded-2xl border bg-card p-5 shadow-sm md:p-6" @submit.prevent="submit">
            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="pan_number">PAN Number</Label>
                        <Input id="pan_number" v-model="form.pan_number" placeholder="ABCDE1234F" class="uppercase" />
                        <InputError :message="form.errors.pan_number" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="aadhaar_number">Aadhaar Number</Label>
                        <Input id="aadhaar_number" v-model="form.aadhaar_number" placeholder="12 digits" inputmode="numeric" />
                        <InputError :message="form.errors.aadhaar_number" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="pan_doc">PAN Document</Label>
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed bg-muted/30 px-4 py-6 text-center transition hover:border-orange-500/50 hover:bg-muted/50">
                            <Upload class="size-5 text-orange-500" />
                            <span class="text-xs font-medium">{{ form.pan_doc?.name ?? 'Upload image / PDF' }}</span>
                            <input id="pan_doc" type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" @change="onFile('pan_doc', $event)" />
                        </label>
                        <InputError :message="form.errors.pan_doc" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="aadhaar_doc">Aadhaar Document</Label>
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border border-dashed bg-muted/30 px-4 py-6 text-center transition hover:border-orange-500/50 hover:bg-muted/50">
                            <Upload class="size-5 text-orange-500" />
                            <span class="text-xs font-medium">{{ form.aadhaar_doc?.name ?? 'Upload image / PDF' }}</span>
                            <input id="aadhaar_doc" type="file" accept=".jpg,.jpeg,.png,.pdf" class="hidden" @change="onFile('aadhaar_doc', $event)" />
                        </label>
                        <InputError :message="form.errors.aadhaar_doc" />
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95 disabled:opacity-50"
                >
                    Submit for verification
                </button>
            </div>
        </form>
    </div>
</template>
