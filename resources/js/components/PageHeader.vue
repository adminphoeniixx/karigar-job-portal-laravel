<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string;
    icon?: Component;
}>();
</script>

<template>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div
                v-if="icon"
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-600 text-white shadow-lg shadow-teal-500/25"
            >
                <component :is="icon" class="size-5" />
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ title }}</h1>
                <p v-if="description" class="mt-0.5 text-sm text-muted-foreground">{{ description }}</p>
            </div>
        </div>
        <div v-if="$slots.action" class="shrink-0">
            <slot name="action" />
        </div>
    </div>
</template>
