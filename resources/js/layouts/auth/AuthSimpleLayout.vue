<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, MapPin, Zap } from '@lucide/vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const highlights = [
    { icon: BadgeCheck, text: 'KYC-verified workers & employers' },
    { icon: MapPin, text: 'Hyperlocal job matching' },
    { icon: Zap, text: 'Instant search, in your language' },
];

// Placeholder marketing stats — replace with real numbers.
const stats = [
    { value: '100%', label: 'KYC verified' },
    { value: '24×7', label: 'Job matching' },
    { value: '10+', label: 'Languages' },
];
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <!-- Brand panel -->
        <div class="relative hidden overflow-hidden bg-[#04100d] p-10 text-white lg:flex lg:flex-col xl:p-12">
            <!-- ambient glows -->
            <div class="pointer-events-none absolute inset-0">
                <div class="absolute left-1/2 top-[-12%] h-[520px] w-[620px] -translate-x-1/2 rounded-full bg-teal-600/25 blur-[140px]"></div>
                <div class="absolute bottom-[-12%] right-[-12%] h-[360px] w-[360px] rounded-full bg-cyan-600/20 blur-[120px]"></div>
            </div>
            <!-- subtle grid texture -->
            <div
                class="pointer-events-none absolute inset-0 opacity-60 [background-image:linear-gradient(to_right,rgba(255,255,255,0.04)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.04)_1px,transparent_1px)] [background-size:44px_44px] [mask-image:radial-gradient(ellipse_at_center,black,transparent_75%)]"
            ></div>

            <!-- Logo (top) -->
            <Link :href="home()" class="relative z-10 flex items-center gap-2.5 text-lg font-bold">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 shadow-lg shadow-teal-500/40">K</span>
                Karigar
            </Link>

            <!-- Hero (vertically centered — fills the gap) -->
            <div class="relative z-10 flex flex-1 flex-col justify-center py-12">
                <span class="mb-5 inline-flex w-fit items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-teal-200 backdrop-blur">
                    <span class="size-1.5 rounded-full bg-teal-400"></span>
                    India's trusted skilled-work marketplace
                </span>
                <h2 class="max-w-md text-4xl font-extrabold leading-[1.1] tracking-tight xl:text-5xl">
                    Skilled work,
                    <span class="bg-gradient-to-r from-teal-400 to-cyan-300 bg-clip-text text-transparent">simplified.</span>
                </h2>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-400">
                    Connecting India's skilled workers with employers — verified, hyperlocal, and instant.
                </p>
                <ul class="mt-9 space-y-3.5">
                    <li
                        v-for="h in highlights"
                        :key="h.text"
                        class="flex items-center gap-3.5 rounded-xl border border-white/5 bg-white/[0.03] px-4 py-3 text-sm text-slate-200 transition hover:border-teal-400/30 hover:bg-white/[0.06]"
                    >
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-teal-500/15 text-teal-300">
                            <component :is="h.icon" class="size-4" />
                        </span>
                        {{ h.text }}
                    </li>
                </ul>
            </div>

            <!-- Stats footer (bottom) -->
            <div class="relative z-10 flex items-center gap-8 border-t border-white/10 pt-6">
                <div v-for="s in stats" :key="s.label">
                    <div class="text-xl font-bold text-white">{{ s.value }}</div>
                    <div class="text-xs text-slate-400">{{ s.label }}</div>
                </div>
            </div>
        </div>

        <!-- Form panel -->
        <div class="flex flex-col items-center justify-center bg-background p-6 md:p-10">
            <div class="w-full max-w-sm">
                <Link :href="home()" class="mb-8 flex items-center justify-center gap-2 text-lg font-bold lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/40">K</span>
                    Karigar
                </Link>
                <div class="flex flex-col gap-8">
                    <div class="space-y-2">
                        <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
                        <p class="text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
