<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { computed, ref } from 'vue';

const props = defineProps<{
    passwordRules: string;
    role?: 'worker' | 'employer';
}>();

const roles = [
    { value: 'worker', label: 'I am a Worker', hint: 'Find jobs near you' },
    { value: 'employer', label: 'I am an Employer', hint: 'Hire skilled workers' },
] as const;

const initialRole = computed(() => {
    if (props.role) {
        return props.role;
    }
    const param = new URLSearchParams(window.location.search).get('role');
    return param === 'employer' ? 'employer' : 'worker';
});

const role = ref<string>(initialRole.value);

// When the role is fixed via the URL (e.g. /worker/register) we hide the chooser.
const roleLocked = computed(() => !!props.role);
const roleLabels: Record<string, string> = { worker: 'Worker', employer: 'Employer' };
const roleLabel = computed(() => roleLabels[role.value] ?? '');
const loginHref = computed(() => (props.role ? `/${props.role}/login` : login().url));

defineOptions({
    layout: {
        title: 'Create an account',
        description: 'Enter your details below to create your account',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-6"
    >
        <div class="grid gap-6">
            <input type="hidden" name="role" :value="role" />

            <div v-if="roleLocked" class="flex items-center justify-center">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-sm font-medium text-primary">
                    Registering as {{ roleLabel }}
                </span>
            </div>

            <div v-else class="grid gap-2">
                <Label>I want to join as</Label>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="option in roles"
                        :key="option.value"
                        type="button"
                        @click="role = option.value"
                        :class="[
                            'rounded-lg border p-3 text-left transition',
                            role === option.value
                                ? 'border-primary ring-2 ring-primary/30'
                                : 'border-input hover:border-primary/50',
                        ]"
                    >
                        <span class="block text-sm font-medium">{{ option.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                    </button>
                </div>
                <InputError :message="errors.role" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Full name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Confirm password"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-2 w-full"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create account
            </Button>
        </div>
        <TextLink
            v-if="role"
            :href="`/${role}/otp-login`"
            class="inline-flex w-full items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold no-underline transition hover:bg-muted"
        >
            📱 Register with mobile OTP
        </TextLink>


        <div class="text-center text-sm text-muted-foreground">
            Already have an account?
            <TextLink
                :href="loginHref"
                class="underline underline-offset-4"
                :tabindex="6"
                >Log in</TextLink
            >
        </div>
    </Form>
</template>
