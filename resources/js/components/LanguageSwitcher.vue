<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { setI18nLocale, type AppLocale } from '@/i18n';

const page = usePage();

const locales = computed(
    () => (page.props.supportedLocales ?? { en: 'English' }) as Record<string, string>,
);
const current = computed(() => (page.props.locale ?? 'en') as string);

function change(event: Event) {
    const locale = (event.target as HTMLSelectElement).value as AppLocale;
    setI18nLocale(locale);
    router.post('/locale', { locale }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <select
        :value="current"
        @change="change"
        class="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
        aria-label="Language"
    >
        <option v-for="(label, code) in locales" :key="code" :value="code">{{ label }}</option>
    </select>
</template>
