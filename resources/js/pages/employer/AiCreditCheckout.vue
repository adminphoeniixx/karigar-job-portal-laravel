<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Coins } from '@lucide/vue';
import { onMounted } from 'vue';

const props = defineProps<{
    razorpayKey: string;
    orderId: string;
    pack: { name: string; total_credits: number };
    amounts: { subtotal: number; gst_percent: number; gst: number; total: number };
    prefill: { name: string | null; email: string | null; contact: string | null };
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'AI Credits', href: '/employer/ai-credits' }, { title: 'Payment', href: '#' }] } });

const inr = (v: number) => '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2 });

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
        amount: Math.round(props.amounts.total * 100),
        currency: 'INR',
        name: 'Super Karigar',
        description: `${props.pack.total_credits} AI call credits`,
        prefill: props.prefill,
        theme: { color: '#bf3a16' },
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        handler: (response: any) => {
            router.post('/employer/ai-credits/callback', {
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature,
            });
        },
        modal: {
            ondismiss: () => router.visit('/employer/ai-credits'),
        },
    });
    rzp.open();
}

onMounted(() => {
    openCheckout().catch(() => {});
});
</script>

<template>
    <Head title="Recharge AI credits" />

    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-12 text-center">
        <span class="flex size-16 items-center justify-center rounded-2xl bg-primary text-white shadow-lg shadow-orange-500/25">
            <Coins class="size-8" />
        </span>
        <h1 class="text-xl font-semibold">{{ pack.total_credits }} AI call credits — {{ pack.name }} pack</h1>
        <div class="w-full max-w-xs rounded-2xl border bg-card p-4 text-left text-sm shadow-sm">
            <div class="flex justify-between py-1"><span class="text-muted-foreground">Price</span><span class="tabular-nums">{{ inr(amounts.subtotal) }}</span></div>
            <div v-if="amounts.gst > 0" class="flex justify-between py-1">
                <span class="text-muted-foreground">GST {{ amounts.gst_percent }}%</span><span class="tabular-nums">{{ inr(amounts.gst) }}</span>
            </div>
            <div class="mt-1 flex justify-between border-t pt-2 font-semibold"><span>Total</span><span class="tabular-nums">{{ inr(amounts.total) }}</span></div>
        </div>
        <p class="max-w-sm text-sm text-muted-foreground">The credits land in your account as soon as the payment goes through, with a GST invoice by email. If the payment window doesn’t open, click below.</p>
        <button
            class="inline-flex items-center gap-1.5 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95"
            @click="openCheckout"
        >
            Open payment
        </button>
    </div>
</template>
