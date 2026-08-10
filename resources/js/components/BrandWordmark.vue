<script setup lang="ts">
/**
 * The brand lockup: "Super कारीगर" — Latin Outfit + Devanagari Mukta, set as
 * one word. Text only; there is no icon in the mark.
 *
 * `tone` picks how the Devanagari half is coloured:
 *   brand — terracotta on paper (default, public pages and the sidebar)
 *   plain — inherits the surrounding colour (dark footers, tight spots)
 */
withDefaults(defineProps<{ tone?: 'brand' | 'plain' }>(), { tone: 'brand' });
</script>

<template>
    <!-- "SUPER" is set in caps, which need positive tracking to breathe; the
         Devanagari half keeps its own spacing. -->
    <span class="whitespace-nowrap font-bold">
        <span class="tracking-[0.02em]">SUPER</span>
        <span class="font-devanagari ml-[0.16em]" :class="tone === 'brand' ? 'text-primary' : ''">कारीगर</span>
    </span>
</template>
