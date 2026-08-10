<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, MapPin, Zap } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BrandWordmark from '@/components/BrandWordmark.vue';
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
    <!-- Editorial split: the form on paper, a full-bleed karigar portrait beside
         it. Same language as the public landing and job pages. -->
    <div class="theme-paper grid min-h-svh bg-background text-foreground antialiased lg:grid-cols-[1fr_1.1fr]">
        <div class="bg-noise relative flex flex-col justify-center px-6 py-12 sm:px-12 lg:px-16">
            <div class="mx-auto w-full max-w-sm">
                <Link :href="home()" class="text-[18px]">
                    <BrandWordmark />
                </Link>

                <div class="mt-14">
                    <div class="flex items-center gap-3 text-primary">
                        <span class="h-px w-8 bg-primary"></span>
                        <span class="label-rule">{{ $t('landing.badge') }}</span>
                    </div>
                    <h1 class="mt-5 text-4xl font-bold leading-[1.05] tracking-[-0.035em]">{{ heading }}</h1>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ subheading }}</p>
                </div>

                <div class="mt-10">
                    <slot />
                </div>

                <div class="mt-12 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-foreground/10 pt-6">
                    <span v-for="h in highlights" :key="h.text" class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <component :is="h.icon" class="size-3.5 text-primary" />
                        {{ h.text }}
                    </span>
                </div>
            </div>
        </div>

        <div class="relative hidden lg:block">
            <img
                src="/images/landing/weaver.jpg"
                alt="Indian handloom weaver at her loom in a Kerala weaving factory"
                class="h-full w-full object-cover"
            />
            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-[#17110c]/85 to-transparent p-12">
                <p class="max-w-md text-2xl font-bold leading-snug tracking-tight text-background">
                    {{ $t('landing.heroTitle') }} <span class="italic text-primary-foreground/90">{{ $t('landing.heroAccent') }}</span>
                </p>
            </div>
        </div>
    </div>
</template>
