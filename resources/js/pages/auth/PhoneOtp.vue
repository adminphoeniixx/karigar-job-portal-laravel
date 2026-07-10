<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, MessageSquareText, Smartphone } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{ role: 'worker' | 'employer' }>();

defineOptions({
    layout: {
        title: 'auth.loginWithMobile',
        description: 'auth.otpSubtitle',
    },
});

const { t } = useI18n();
const roleLabel = computed(() => (props.role === 'worker' ? t('auth.worker') : t('auth.employer')));

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
            <Smartphone class="size-3.5" /> {{ roleLabel }} · {{ $t('auth.otpLogin') }}
        </div>

        <!-- Step 1: phone -->
        <form v-if="step === 'phone'" class="flex flex-col gap-5" @submit.prevent="sendOtp">
            <div class="grid gap-2">
                <Label for="phone">{{ $t('auth.mobileNumber') }}</Label>
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
                <p class="text-xs text-muted-foreground">{{ $t('auth.newNumberHint') }}</p>
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing || form.phone.length !== 10">
                <Spinner v-if="form.processing" class="mr-1 size-4" />
                {{ $t('auth.sendOtp') }}
            </Button>
        </form>

        <!-- Step 2: OTP -->
        <form v-else class="flex flex-col gap-5" @submit.prevent="verify">
            <div class="rounded-xl border bg-accent/60 px-4 py-3 text-sm">
                <div class="flex items-center gap-2 font-medium"><MessageSquareText class="size-4 text-primary" /> {{ $t('auth.otpSentTo') }} +91 {{ form.phone }}</div>
                <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline" @click="editNumber">
                    <ArrowLeft class="size-3" /> {{ $t('auth.changeNumber') }}
                </button>
            </div>

            <div class="grid gap-2">
                <Label for="otp">{{ $t('auth.enterOtp') }}</Label>
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
                {{ $t('auth.verifyContinue') }}
            </Button>

            <button
                type="button"
                class="text-center text-sm font-medium transition"
                :class="cooldown > 0 ? 'cursor-default text-muted-foreground' : 'text-primary hover:underline'"
                :disabled="cooldown > 0 || form.processing"
                @click="sendOtp"
            >
                {{ cooldown > 0 ? $t('auth.resendIn', { s: cooldown }) : $t('auth.resendOtp') }}
            </button>
        </form>

    </div>
</template>
