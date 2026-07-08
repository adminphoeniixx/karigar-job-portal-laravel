<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { onMounted } from 'vue';

interface Plan {
    name: string;
    price: string;
}

const props = defineProps<{
    razorpayKey: string;
    subscriptionId: string;
    plan: Plan;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Checkout', href: '/subscription' }] } });

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
        subscription_id: props.subscriptionId,
        name: 'Karigar',
        description: `${props.plan.name} plan`,
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        handler: (response: any) => {
            router.post('/subscription/callback', {
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_subscription_id: response.razorpay_subscription_id,
                razorpay_signature: response.razorpay_signature,
            });
        },
    });
    rzp.open();
}

onMounted(() => {
    openCheckout().catch(() => {});
});
</script>

<template>
    <Head title="Checkout" />

    <div class="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-12 text-center">
        <span class="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-lg shadow-orange-500/25">
            <Loader2 class="size-8 animate-spin" />
        </span>
        <h1 class="text-xl font-semibold">Completing your {{ plan.name }} subscription…</h1>
        <p class="max-w-sm text-sm text-muted-foreground">If the Razorpay window does not open automatically, click the button below.</p>
        <button
            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-rose-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-orange-600/25 transition hover:opacity-90 active:scale-95"
            @click="openCheckout"
        >
            Open payment
        </button>
    </div>
</template>
