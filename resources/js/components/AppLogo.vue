<script setup lang="ts">
import BrandWordmark from '@/components/BrandWordmark.vue';
</script>

<template>
    <!-- Collapsed sidebar keeps only the "S": the wordmark is text, so the
         compact form is the initial rather than an icon. -->
    <div
        class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-sm bg-sidebar-primary text-base font-bold text-sidebar-primary-foreground group-data-[collapsible=icon]:flex"
    >
        S
    </div>
    <div class="ml-1.5 grid flex-1 text-left text-[15px] group-data-[collapsible=icon]:hidden">
        <BrandWordmark class="truncate leading-tight" tone="plain" />
    </div>
</template>
