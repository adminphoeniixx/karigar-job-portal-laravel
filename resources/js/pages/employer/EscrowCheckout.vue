<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { onMounted } from 'vue';

const props = defineProps<{
    razorpayKey: string;
    escrowId: number;
    jobId: number;
    orderId: string;
    amount: number;
    currency: string;
    workerName: string;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'My Jobs', href: '/employer/jobs' }, { title: 'Payment', href: '#' }] } });

function loadRazorpay(): Promise<void> {
    return new Promise((resolve, reject) => {
        if ((window as unknown as { Razorpay?: unknown }).Razorpay) {
            return resolve();
        }
        const script = document.createElement('script');
        script.src = 'https://checkout.razorpay.com/v1/checkout.js';
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Failed to load Razorpay'));
        document.head.appendChild(script);
    });
}

async function openCheckout() {
    await loadRazorpay();

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const RazorpayCtor = (window as any).Razorpay;
    const rzp = new RazorpayCtor({
        key: props.razorpayKey,
        order_id: props.orderId,
        amount: props.amount,
        currency: props.currency,
        name: 'Karigar',
        description: `Payment for ${props.workerName}`,
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        handler: (response: any) => {
            router.post(`/employer/escrows/${props.escrowId}/callback`, {
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature,
            });
        },
        modal: {
            ondismiss: () => router.visit(`/employer/jobs/${props.jobId}/applicants`),
        },
    });
    rzp.open();
}

onMounted(() => {
    openCheckout().catch(() => {});
});

const inr = '₹' + new Intl.NumberFormat('en-IN').format(Math.round(props.amount / 100));
</script>

<template>
    <Head title="Secure payment" />

    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-12 text-center">
        <span class="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/25">
            <Loader2 class="size-8 animate-spin" />
        </span>
        <h1 class="text-xl font-semibold">Securing {{ inr }} for {{ workerName }}…</h1>
        <p class="max-w-sm text-sm text-muted-foreground">The funds are held safely and only released to the worker once you confirm the job is done. If the payment window doesn’t open, click below.</p>
        <button
            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-teal-500 to-cyan-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-600/25 transition hover:opacity-90 active:scale-95"
            @click="openCheckout"
        >
            Open payment
        </button>
    </div>
</template>
