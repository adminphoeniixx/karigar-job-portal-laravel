<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, MessageSquareText, Smartphone } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{ role: 'worker' | 'employer' }>();

defineOptions({
    layout: {
        title: 'Login with mobile number',
        description: 'We’ll send a one-time password to your phone',
    },
});

const roleLabel = computed(() => (props.role === 'worker' ? 'Worker' : 'Employer'));

const step = ref<'phone' | 'otp'>('phone');

const form = useForm({
    phone: '',
    otp: '',
});

// Resend cooldown
const cooldown = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

const startCooldown = () => {
    cooldown.value = 30;
    clearInterval(timer);
    timer = setInterval(() => {
        if (--cooldown.value <= 0) clearInterval(timer);
    }, 1000);
};

onBeforeUnmount(() => clearInterval(timer));

const sendOtp = () => {
    form.clearErrors();
    form.post('/otp/send', {
        preserveScroll: true,
        onSuccess: () => {
            step.value = 'otp';
            form.otp = '';
            startCooldown();
        },
    });
};

const verify = () => {
    form.post(`/${props.role}/otp/verify`, { preserveScroll: true });
};

const editNumber = () => {
    step.value = 'phone';
    form.otp = '';
    form.clearErrors();
};
</script>

<template>
    <Head :title="`${roleLabel} login with OTP`" />

    <div class="flex flex-col gap-6">
        <div class="inline-flex items-center gap-2 self-start rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground">
            <Smartphone class="size-3.5" /> {{ roleLabel }} · OTP login
        </div>

        <!-- Step 1: phone -->
        <form v-if="step === 'phone'" class="flex flex-col gap-5" @submit.prevent="sendOtp">
            <div class="grid gap-2">
                <Label for="phone">Mobile number</Label>
                <div class="flex items-center gap-2">
                    <span class="flex h-9 items-center rounded-md border bg-muted px-3 text-sm font-semibold text-muted-foreground">+91</span>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        inputmode="numeric"
                        maxlength="10"
                        placeholder="9876543210"
                        autofocus
                        required
                        class="flex-1 tracking-wide"
                    />
                </div>
                <InputError :message="form.errors.phone" />
                <p class="text-xs text-muted-foreground">New number? We’ll create your {{ roleLabel.toLowerCase() }} account automatically.</p>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing || form.phone.length !== 10">
                <Spinner v-if="form.processing" class="mr-1 size-4" />
                Send OTP
            </Button>
        </form>

        <!-- Step 2: OTP -->
        <form v-else class="flex flex-col gap-5" @submit.prevent="verify">
            <div class="rounded-xl border bg-accent/60 px-4 py-3 text-sm">
                <div class="flex items-center gap-2 font-medium"><MessageSquareText class="size-4 text-primary" /> OTP sent to +91 {{ form.phone }}</div>
                <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline" @click="editNumber">
                    <ArrowLeft class="size-3" /> Change number
                </button>
            </div>

            <div class="grid gap-2">
                <Label for="otp">Enter 4-digit OTP</Label>
                <Input
                    id="otp"
                    v-model="form.otp"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="4"
                    placeholder="••••"
                    autofocus
                    required
                    class="text-center text-2xl font-bold tracking-[0.5em]"
                />
                <InputError :message="form.errors.otp" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing || form.otp.length !== 4">
                <Spinner v-if="form.processing" class="mr-1 size-4" />
                Verify & continue
            </Button>

            <button
                type="button"
                class="text-center text-sm font-medium transition"
                :class="cooldown > 0 ? 'cursor-default text-muted-foreground' : 'text-primary hover:underline'"
                :disabled="cooldown > 0 || form.processing"
                @click="sendOtp"
            >
                {{ cooldown > 0 ? `Resend OTP in ${cooldown}s` : 'Resend OTP' }}
            </button>
        </form>

    </div>
</template>
