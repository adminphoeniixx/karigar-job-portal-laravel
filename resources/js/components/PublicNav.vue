<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import LanguageSwitcher from '@/components/LanguageSwitcher.vue';

/**
 * The bar every public page wears: landing, job browse, job detail. Kept in one
 * place so the three never drift apart again. Centre links are page-specific,
 * so they come in through the default slot.
 */
const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
    <header class="sticky top-0 z-40 border-b border-foreground/10 bg-background/85 backdrop-blur">
        <div class="mx-auto flex max-w-[88rem] items-center justify-between px-6 py-4 lg:px-10">
            <Link href="/" class="text-[18px]">
                <BrandWordmark />
            </Link>
            <nav class="hidden items-center gap-8 text-[13px] font-medium text-muted-foreground lg:flex">
                <slot />
            </nav>
            <div class="flex items-center gap-3">
                <LanguageSwitcher />
                <Link
                    v-if="user"
                    href="/dashboard"
                    class="rounded-sm bg-foreground px-4 py-2 text-[13px] font-semibold text-background transition hover:bg-primary"
                >
                    {{ $t('nav.dashboard') }}
                </Link>
                <Link v-else href="/employer/login" class="rounded-sm bg-foreground px-4 py-2 text-[13px] font-semibold text-background transition hover:bg-primary">
                    {{ $t('common.login') }}
                </Link>
            </div>
        </div>
    </header>
</template>
