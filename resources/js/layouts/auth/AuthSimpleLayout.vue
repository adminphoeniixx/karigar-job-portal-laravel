<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, MapPin, Zap } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { home } from '@/routes';

const props = defineProps<{
    title?: string;
    description?: string;
}>();

const { t, te } = useI18n();

// Pages may pass either literal text or an i18n key.
const heading = computed(() => (props.title && te(props.title) ? t(props.title) : props.title));
const subheading = computed(() => (props.description && te(props.description) ? t(props.description) : props.description));

const highlights = [
    { icon: BadgeCheck, text: 'KYC verified' },
    { icon: MapPin, text: 'Hyperlocal jobs' },
    { icon: Zap, text: 'Instant search' },
];
</script>

<template>
    <div class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10">
        <!-- Ambient tints (matches the landing page theme) -->
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute left-1/2 top-[-15%] h-[480px] w-[720px] -translate-x-1/2 rounded-full bg-orange-500/10 blur-[140px]"></div>
            <div class="absolute bottom-[-15%] right-[-8%] h-[360px] w-[360px] rounded-full bg-rose-400/10 blur-[120px]"></div>
        </div>
        <div class="pointer-events-none absolute inset-0 bg-grid opacity-[0.5] [mask-image:radial-gradient(ellipse_at_center,black,transparent_75%)]"></div>

        <div class="relative w-full max-w-md">
            <!-- Logo -->
            <Link :href="home()" class="mb-6 flex items-center justify-center gap-2.5 text-xl font-bold">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-rose-600 text-white shadow-lg shadow-orange-500/30">K</span>
                Karigar
            </Link>

            <!-- Centered card -->
            <div class="rounded-3xl border bg-card p-6 shadow-premium md:p-8">
                <div class="mb-8 space-y-2 text-center">
                    <h1 class="text-2xl font-bold tracking-tight">{{ heading }}</h1>
                    <p class="text-sm text-muted-foreground">{{ subheading }}</p>
                </div>
                <slot />
            </div>

            <!-- Trust badges -->
            <div class="mt-6 flex items-center justify-center gap-6">
                <span
                    v-for="h in highlights"
                    :key="h.text"
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    <span class="flex size-6 items-center justify-center rounded-full bg-accent text-primary">
                        <component :is="h.icon" class="size-3.5" />
                    </span>
                    {{ h.text }}
                </span>
            </div>
        </div>
    </div>
</template>
