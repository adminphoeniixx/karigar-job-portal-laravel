<script setup lang="ts">
/**
 * The registered entity behind the brand — name, address, GSTIN and how to
 * reach it. Razorpay and the GST rules both want this visible on the site, not
 * only on the invoice, so every public footer carries it.
 *
 * The values come from config/company.php via the shared `company` prop, so
 * they are set in one place and printed the same way here, on tax invoices and
 * in outgoing mail.
 *
 * `variant` picks the shape:
 *   block — stacked lines, for the landing page's tall footer column
 *   line  — one wrapping run separated by dots, for the slim page footers
 *
 * It sets no colour of its own: it inherits, so the same component reads
 * correctly in the ink footers and on the paper-coloured privacy page.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

withDefaults(defineProps<{ variant?: 'block' | 'line' }>(), { variant: 'block' });

type Company = {
    legal_name?: string;
    address?: string;
    gstin?: string;
    email?: string;
    phone?: string;
};

const company = computed(() => (usePage().props.company as Company | undefined) ?? {});
</script>

<template>
    <div v-if="variant === 'block'" class="space-y-1.5 text-xs leading-relaxed">
        <div class="font-semibold">{{ company.legal_name }}</div>
        <div>{{ company.address }}</div>
        <div v-if="company.gstin">GSTIN {{ company.gstin }}</div>
        <div class="flex flex-wrap gap-x-4 gap-y-1 pt-1">
            <a v-if="company.email" :href="`mailto:${company.email}`" class="underline-offset-2 transition hover:underline">{{ company.email }}</a>
            <a v-if="company.phone" :href="`tel:${company.phone}`" class="underline-offset-2 transition hover:underline">{{ company.phone }}</a>
        </div>
    </div>

    <p v-else class="max-w-3xl text-xs leading-relaxed">
        <span class="font-semibold">{{ company.legal_name }}</span>
        <span v-if="company.address"> · {{ company.address }}</span>
        <span v-if="company.gstin"> · GSTIN {{ company.gstin }}</span>
        <span v-if="company.email"> · <a :href="`mailto:${company.email}`" class="underline-offset-2 transition hover:underline">{{ company.email }}</a></span>
        <span v-if="company.phone"> · <a :href="`tel:${company.phone}`" class="underline-offset-2 transition hover:underline">{{ company.phone }}</a></span>
    </p>
</template>
