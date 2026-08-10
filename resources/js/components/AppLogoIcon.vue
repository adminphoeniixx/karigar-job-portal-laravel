<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Super Karigar mark: a ribbed hard hat — the trades read it instantly and
         it still holds together at favicon size. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="currentColor"
        :class="className"
        v-bind="$attrs"
    >
        <path d="M12 3.2c1.7 0 2.7 1.1 2.7 2.9v8.6H9.3V6.1c0-1.8 1-2.9 2.7-2.9Z" />
        <path d="M8.1 7.5v7.2H4.4A8 8 0 0 1 8.1 7.5Z" />
        <path d="M15.9 7.5v7.2h3.7a8 8 0 0 0-3.7-7.2Z" />
        <rect x="2" y="15.9" width="20" height="2.9" rx="1.45" />
    </svg>
</template>
